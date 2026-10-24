<?php

namespace App\Assets;

use App\Enums\FileRole;
use App\Enums\MediaType;
use App\Enums\VersionStatus;
use App\Jobs\ProcessAssetVersion;
use App\Media\FileInspector;
use App\Media\MediaTypeRegistry;
use App\Media\ProcessingContext;
use App\Media\Scanning\MalwareScanner;
use App\Models\AssetFile;
use App\Models\AssetVersion;
use App\States\Asset\AssetState;
use App\States\Asset\Processing;
use App\States\Asset\ProcessingFailed;
use App\States\Asset\PublicationPending;
use App\States\Asset\Published;
use App\States\Asset\ReadyForReview;
use App\States\Asset\Validating;
use App\Storage\StorageManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Upload → Validate → Malware scan → Store original → Extract metadata →
 * Derivatives → Verify output → Ready for review.
 *
 * Every step is idempotent so queued jobs can be retried safely.
 */
class VersionPipeline
{
    public function __construct(
        private readonly StorageManager $storage,
        private readonly MalwareScanner $scanner,
        private readonly FileInspector $inspector,
        private readonly MediaTypeRegistry $registry,
    ) {}

    /** Validate, scan and move the original from quarantine into storage. */
    public function ingest(AssetVersion $version): void
    {
        if ($version->status->isTerminal() || $version->status === VersionStatus::Processing) {
            return;
        }

        $asset = $version->asset;

        if (! $version->files()->where('role', FileRole::Original->value)->exists()) {
            $quarantine = Storage::disk(config('dam.storage.quarantine_disk'));

            if (! $version->quarantine_path || ! $quarantine->exists($version->quarantine_path)) {
                throw new RuntimeException('The uploaded file is no longer available in quarantine.');
            }

            $this->status($version, VersionStatus::Validating, 'validating', 5);
            $this->moveAsset($version, Validating::class);

            $localPath = $quarantine->path($version->quarantine_path);

            if (hash_file('sha256', $localPath) !== $version->checksum_sha256) {
                throw new RuntimeException('Checksum mismatch: the quarantined file changed after upload.');
            }

            $detected = $this->inspector->detectMime($localPath, (string) $version->extension);

            if ($detected !== $version->detected_mime) {
                throw new RuntimeException('File type re-validation failed.');
            }

            $this->status($version, VersionStatus::Scanning, 'malware_scan', 15);
            $scan = $this->scanner->scan($localPath);

            if (! $scan->clean) {
                $quarantine->delete($version->quarantine_path);
                $version->forceFill(['quarantine_path' => null])->saveQuietly();

                activity('security')->performedOn($asset)->event('malware_detected')
                    ->withProperties(['version' => $version->version_number, 'signature' => $scan->signature, 'scanner' => $scan->scanner])
                    ->log('Malware detected in upload; file destroyed');

                throw new RuntimeException("The file was rejected by the malware scanner ({$scan->signature}) and has been destroyed.");
            }

            $version->forceFill(['metadata' => array_merge($version->metadata ?? [], ['scanner' => $scan->scanner, 'scanned_at' => now()->toIso8601String()])])->saveQuietly();

            $this->storeOriginal($version, $localPath);
            $quarantine->delete($version->quarantine_path);
            $version->forceFill(['quarantine_path' => null])->saveQuietly();
        }

        if ($asset->media_type === MediaType::Other) {
            // Stored for administrative review only: never viewable or publishable.
            $this->moveAsset($version, Processing::class);
            $this->complete($version, viewable: false);

            return;
        }

        $this->status($version, VersionStatus::Processing, 'queued', 25);
        ProcessAssetVersion::dispatch($version->id)->onQueue($this->registry->queueFor($asset->media_type));
    }

    /** Generate and verify viewing derivatives. */
    public function process(AssetVersion $version): void
    {
        if ($version->status === VersionStatus::Ready || $version->status === VersionStatus::Superseded) {
            return;
        }

        $asset = $version->asset;
        $original = $version->file(FileRole::Original) ?? throw new RuntimeException('Original file record is missing.');
        $processor = $this->registry->processorFor($asset->media_type) ?? throw new RuntimeException('No processor registered for '.$asset->media_type->value.'.');

        $this->clearDerivatives($version);
        $version->forceFill([
            'status' => VersionStatus::Processing,
            'processing_started_at' => now(),
            'processing_error' => null,
            'processing_attempts' => $version->processing_attempts + 1,
        ])->save();
        $this->moveAsset($version, Processing::class);

        $workDir = rtrim(config('dam.storage.work_path'), '/\\').DIRECTORY_SEPARATOR.$version->uuid.'-'.Str::random(8);
        File::ensureDirectoryExists($workDir, 0700);

        try {
            $source = $this->localCopy($version, $original, $workDir);
            $context = new ProcessingContext($version, $workDir, $source, $this->storage);
            $context->progress('derivatives', 30);

            $processor->process($context);

            $version->unsetRelation('files');

            foreach ($processor->requiredRoles($context) as $role) {
                $file = $version->file($role);

                if (! $file || ! $this->storage->disk($file->storageBackend)->exists($file->path)) {
                    throw new RuntimeException("Verification failed: {$role->value} derivative is missing.");
                }
            }

            $this->complete($version, viewable: true);
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    public function fail(AssetVersion $version, Throwable|string $error): void
    {
        $message = $error instanceof Throwable ? $error->getMessage() : $error;

        $version->forceFill([
            'status' => VersionStatus::Failed,
            'processing_error' => Str::limit($message, 2000),
            'processing_completed_at' => now(),
        ])->save();

        $this->moveAsset($version, ProcessingFailed::class);

        activity('processing')->performedOn($version->asset)->event('processing_failed')
            ->withProperties(['version' => $version->version_number, 'error' => Str::limit($message, 500)])
            ->log('Media processing failed');

        Log::warning('Asset version processing failed', ['version' => $version->uuid, 'error' => $message]);
    }

    /** Whether this version replaces a live (published) one; live assets keep serving the old version. */
    public function isLiveReplacement(AssetVersion $version): bool
    {
        $asset = $version->asset;

        return $asset->current_version_id !== null
            && $asset->current_version_id !== $version->id
            && ($asset->state instanceof Published || $asset->state->equals(PublicationPending::class));
    }

    private function complete(AssetVersion $version, bool $viewable): void
    {
        DB::transaction(function () use ($version, $viewable) {
            $version->forceFill([
                'status' => VersionStatus::Ready,
                'is_viewable' => $viewable,
                'processing_stage' => 'complete',
                'processing_progress' => 100,
                'processing_completed_at' => now(),
            ])->save();

            if ($this->isLiveReplacement($version)) {
                return; // awaits explicit activation by an authorised user
            }

            $asset = $version->asset;
            $previous = $asset->current_version_id;

            $asset->forceFill(['current_version_id' => $version->id])->save();
            $version->forceFill(['activated_at' => now()])->save();

            if ($previous && $previous !== $version->id) {
                AssetVersion::whereKey($previous)->update(['status' => VersionStatus::Superseded->value]);
            }

            $this->moveAsset($version, ReadyForReview::class);
        });

        activity('processing')->performedOn($version->asset)->event('processing_complete')
            ->withProperties(['version' => $version->version_number, 'viewable' => $viewable])
            ->log('Media processing completed');
    }

    private function storeOriginal(AssetVersion $version, string $localPath): void
    {
        $asset = $version->asset;
        $backend = $version->storageBackend ?? $this->storage->default($asset->media_type->value);
        $version->forceFill(['storage_backend_id' => $backend->id])->save();
        $version->setRelation('storageBackend', $backend);

        $this->status($version, VersionStatus::Validating, 'storing_original', 20);

        $path = $version->storagePrefix().'/original/'.Str::random(24).'.'.$version->extension;
        $disk = $this->storage->disk($backend);
        $stream = fopen($localPath, 'rb');

        try {
            $disk->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($disk->size($path) !== $version->size_bytes) {
            $disk->delete($path);

            throw new RuntimeException('Stored original failed size verification.');
        }

        $version->files()->create([
            'role' => FileRole::Original,
            'storage_backend_id' => $backend->id,
            'path' => $path,
            'mime' => $version->detected_mime ?? 'application/octet-stream',
            'size_bytes' => $version->size_bytes,
            'checksum_sha256' => $version->checksum_sha256,
        ]);
    }

    private function localCopy(AssetVersion $version, AssetFile $original, string $workDir): string
    {
        $target = $workDir.DIRECTORY_SEPARATOR.'source.'.$version->extension;
        $read = $this->storage->disk($original->storageBackend)->readStream($original->path);
        $write = fopen($target, 'wb');

        try {
            stream_copy_to_stream($read, $write);
        } finally {
            if (is_resource($read)) {
                fclose($read);
            }
            fclose($write);
        }

        if (hash_file('sha256', $target) !== $original->checksum_sha256) {
            throw new RuntimeException('Stored original failed checksum verification.');
        }

        return $target;
    }

    private function clearDerivatives(AssetVersion $version): void
    {
        $derivatives = $version->files()->where('role', '!=', FileRole::Original->value)->get();

        if ($derivatives->isNotEmpty() && $version->storageBackend) {
            $this->storage->disk($version->storageBackend)->deleteDirectory($version->storagePrefix().'/derivatives');
        }

        $version->files()->where('role', '!=', FileRole::Original->value)->delete();
        $version->unsetRelation('files');
    }

    private function status(AssetVersion $version, VersionStatus $status, string $stage, int $progress): void
    {
        $version->forceFill(['status' => $status, 'processing_stage' => $stage, 'processing_progress' => $progress])->save();
    }

    /**
     * @param  class-string<AssetState>  $state
     */
    private function moveAsset(AssetVersion $version, string $state): void
    {
        if ($this->isLiveReplacement($version)) {
            return;
        }

        $asset = $version->asset;

        if (! $asset->state->equals($state) && $asset->state->canTransitionTo($state)) {
            $asset->state->transitionTo($state);
        }
    }
}

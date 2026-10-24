<?php

namespace App\Assets;

use App\Enums\MediaType;
use App\Enums\VersionStatus;
use App\Jobs\IngestAssetVersion;
use App\Koha\BiblioRepository;
use App\Koha\ItemRepository;
use App\Media\FileInspector;
use App\Media\MediaTypeRegistry;
use App\Models\AssetVersion;
use App\Models\DigitalAsset;
use App\Models\User;
use App\States\Asset\Archived;
use App\States\Asset\Uploaded;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * Accepts a completely received upload, validates it and places it in the
 * private quarantine area. Nothing here makes a file reachable; the
 * IngestAssetVersion job scans and stores it, and publication is a
 * separate, policy-controlled step.
 */
class UploadIntake
{
    public function __construct(
        private readonly FileInspector $inspector,
        private readonly MediaTypeRegistry $registry,
        private readonly BiblioRepository $biblios,
        private readonly ItemRepository $items,
    ) {}

    /**
     * Chunk size handed to the browser uploader: the configured size, capped
     * by this PHP's upload_max_filesize and post_max_size (minus room for the
     * multipart form fields), so chunks are never silently dropped by PHP.
     */
    public static function chunkBytes(): int
    {
        $limits = [(int) config('dam.upload.chunk_bytes')];

        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $bytes = ini_parse_quantity((string) ini_get($setting));

            if ($bytes > 0) {
                $limits[] = $bytes - 64 * 1024;
            }
        }

        return max(256 * 1024, min($limits));
    }

    /**
     * @param  array{title?: ?string, description?: ?string, change_note?: ?string}  $attributes
     * @param  int|null  $itemId  Koha item of the record (item-level attachment); ignored for a replacement
     * @return array{version: AssetVersion, warnings: list<string>}
     *
     * @throws UploadRejectedException
     */
    public function accept(UploadedFile $file, User $user, int $biblioId, ?DigitalAsset $replacing = null, array $attributes = [], ?int $itemId = null): array
    {
        $itemId = $replacing ? $replacing->item_id : $itemId;
        $warnings = [];
        $path = $file->getRealPath();
        $size = (int) filesize($path);
        $extension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        $originalName = $this->cleanName($file->getClientOriginalName());

        if ($size <= 0) {
            throw new UploadRejectedException('The file is empty.');
        }

        if ($size > config('dam.upload.max_bytes')) {
            throw new UploadRejectedException('The file exceeds the maximum upload size of '.Number::fileSize(config('dam.upload.max_bytes')).'.');
        }

        if ($extension === '' || ! preg_match('/^[a-z0-9]{1,10}$/', $extension)) {
            throw new UploadRejectedException('The file has no valid extension.');
        }

        $detectedMime = $this->inspector->detectMime($path, $extension);
        $mediaType = $this->registry->resolve($detectedMime, $extension);

        if ($mediaType === null) {
            if (! config('dam.upload.allow_unsupported') || in_array($extension, ['php', 'phtml', 'phar', 'exe', 'dll', 'bat', 'cmd', 'sh', 'js', 'html', 'htm', 'svgz'], true)) {
                throw new UploadRejectedException("The file content ({$detectedMime}) does not match an allowed type for “.{$extension}” files.");
            }

            $mediaType = MediaType::Other;
            $warnings[] = 'This format is not supported for viewing. It will be stored for administrative review only and cannot be published.';
        }

        if ($replacing && $replacing->media_type !== $mediaType) {
            throw new UploadRejectedException("A replacement must be the same kind of media ({$replacing->media_type->label()}).");
        }

        $this->enforceQuota($user, $size);

        $checksum = hash_file('sha256', $path);
        $duplicate = $this->findDuplicate($biblioId, $itemId, $checksum);

        if ($duplicate) {
            $message = $itemId === null
                ? "An identical file is already attached to this record as “{$duplicate->asset->title}”."
                : "An identical file is already attached to this item as “{$duplicate->asset->title}”.";

            if (config('dam.upload.duplicate_policy') === 'block') {
                throw new UploadRejectedException($message);
            }

            $warnings[] = $message;
        }

        // Randomised quarantine name: the client filename never reaches the filesystem.
        $quarantinePath = 'incoming/'.Str::uuid()->toString().'.bin';
        $stream = fopen($path, 'rb');

        try {
            Storage::disk(config('dam.storage.quarantine_disk'))->writeStream($quarantinePath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $snapshot = null;
        $itemSnapshot = $itemId === null ? null : $this->items->cached($itemId);
        $version = DB::transaction(function () use (&$snapshot, $itemSnapshot, $itemId, $replacing, $user, $biblioId, $attributes, $mediaType, $originalName, $file, $detectedMime, $extension, $size, $checksum, $quarantinePath) {
            $asset = $replacing ?? DigitalAsset::create([
                'koha_instance_id' => config('dam.koha.instance_id'),
                'biblio_id' => $biblioId,
                'item_id' => $itemId,
                'item_snapshot' => $itemSnapshot,
                'item_snapshot_at' => $itemSnapshot ? now() : null,
                'title' => Str::limit(trim($attributes['title'] ?? '') ?: pathinfo($originalName, PATHINFO_FILENAME), 250, ''),
                'description' => $attributes['description'] ?? null,
                'media_type' => $mediaType,
                'access_level' => 'public',
                'marc_relationship' => 'resource',
                'created_by' => $user->id,
                'updated_by' => $user->id,
                // Display copy of the Koha metadata, so viewers never depend on Koha.
                'biblio_snapshot' => $snapshot = $this->biblios->cached($biblioId),
                'biblio_snapshot_at' => $snapshot ? now() : null,
            ]);

            if (! $replacing) {
                $asset->state->transitionTo(Uploaded::class);
            }

            $number = (int) AssetVersion::where('digital_asset_id', $asset->id)->lockForUpdate()->max('version_number') + 1;

            return $asset->versions()->create([
                'version_number' => $number,
                'status' => VersionStatus::Received,
                'original_filename' => $originalName,
                'client_mime' => Str::limit((string) $file->getClientMimeType(), 120, ''),
                'detected_mime' => $detectedMime,
                'extension' => $extension,
                'size_bytes' => $size,
                'checksum_sha256' => $checksum,
                'quarantine_path' => $quarantinePath,
                'change_note' => $attributes['change_note'] ?? null,
                'uploaded_by' => $user->id,
            ]);
        });

        activity('asset')->causedBy($user)->performedOn($version->asset)->event($replacing ? 'version_uploaded' : 'uploaded')
            ->withProperties(['version' => $version->version_number, 'size' => $size, 'mime' => $detectedMime, 'sha256' => $checksum])
            ->log($replacing ? "Uploaded replacement version {$version->version_number}" : 'Uploaded new asset');

        IngestAssetVersion::dispatch($version->id)->afterCommit();

        return ['version' => $version, 'warnings' => $warnings];
    }

    private function findDuplicate(int $biblioId, ?int $itemId, string $checksum): ?AssetVersion
    {
        return AssetVersion::query()
            ->with('asset')
            ->where('checksum_sha256', $checksum)
            ->where('status', '!=', VersionStatus::Failed->value)
            ->whereHas('asset', fn ($q) => $q->forBiblio($biblioId)->where('item_id', $itemId)->whereNotState('state', Archived::class))
            ->first();
    }

    private function enforceQuota(User $user, int $size): void
    {
        $quota = (int) config('dam.upload.daily_quota_bytes');

        if ($quota <= 0) {
            return;
        }

        $used = (int) AssetVersion::where('uploaded_by', $user->id)->where('created_at', '>=', now()->subDay())->sum('size_bytes');

        if ($used + $size > $quota) {
            throw new UploadRejectedException('Your 24-hour upload quota has been reached. Please try again later or contact an administrator.');
        }
    }

    private function cleanName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\]/u', '', $name) ?? 'upload';

        return Str::limit(trim($name) ?: 'upload', 200, '');
    }
}

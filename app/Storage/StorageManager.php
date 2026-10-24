<?php

namespace App\Storage;

use App\Models\StorageBackend;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Resolves storage backends to Laravel/Flysystem disks.
 *
 * Local, NAS and shared-drive backends are all private "local" disks rooted
 * at an operating-system mount point (mounting and SMB/NFS credentials are
 * handled by the OS, never by the application). S3 backends target MinIO or
 * any S3-compatible service; credentials come from a named profile in
 * config('dam.storage.s3_credentials'), i.e. from the environment.
 */
class StorageManager
{
    /** @var array<int, Filesystem> */
    private array $disks = [];

    public function disk(StorageBackend|int $backend): Filesystem
    {
        $backend = $backend instanceof StorageBackend ? $backend : StorageBackend::findOrFail($backend);

        return $this->disks[$backend->id] ??= Storage::build($this->diskConfig($backend));
    }

    public function default(?string $mediaType = null): StorageBackend
    {
        $backends = StorageBackend::query()->active()->orderByDesc('is_default')->orderBy('id')->get();

        $backend = $backends->first(fn (StorageBackend $b) => $b->is_default && ($mediaType === null || $b->acceptsMediaType($mediaType)))
            ?? $backends->first(fn (StorageBackend $b) => $mediaType === null || $b->acceptsMediaType($mediaType));

        if (! $backend) {
            throw new RuntimeException('No active storage backend accepts '.($mediaType ?? 'files').'.');
        }

        return $backend;
    }

    public function diskConfig(StorageBackend $backend): array
    {
        $settings = $backend->settings ?? [];

        if ($backend->isFilesystem()) {
            $root = $settings['root'] ?? null;

            if (! $root) {
                throw new RuntimeException("Storage backend [{$backend->key}] has no root path.");
            }

            return [
                'driver' => 'local',
                'root' => $root,
                'visibility' => 'private',
                'throw' => true,
                'permissions' => [
                    'file' => ['public' => 0640, 'private' => 0640],
                    'dir' => ['public' => 0750, 'private' => 0750],
                ],
            ];
        }

        if ($backend->driver === 's3') {
            $credentials = config('dam.storage.s3_credentials.'.($settings['credentials_profile'] ?? 'default'), []);

            return [
                'driver' => 's3',
                'key' => $credentials['key'] ?? null,
                'secret' => $credentials['secret'] ?? null,
                'region' => $settings['region'] ?? 'us-east-1',
                'bucket' => $settings['bucket'] ?? null,
                'endpoint' => $settings['endpoint'] ?? null,
                'use_path_style_endpoint' => (bool) ($settings['use_path_style_endpoint'] ?? true),
                'root' => $settings['prefix'] ?? null,
                'visibility' => 'private',
                'throw' => true,
            ];
        }

        throw new RuntimeException("Unsupported storage driver [{$backend->driver}].");
    }

    /**
     * Absolute filesystem path for local-type backends (used by X-Accel /
     * X-Sendfile delivery and to avoid copying files for processing).
     */
    public function absolutePath(StorageBackend $backend, string $path): ?string
    {
        if (! $backend->isFilesystem()) {
            return null;
        }

        return rtrim($backend->root(), '/\\').DIRECTORY_SEPARATOR.ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }

    /**
     * Write, read back and delete a probe object, and report capacity.
     *
     * @return array{ok: bool, message: string, free_bytes: int|null, total_bytes: int|null}
     */
    public function probe(StorageBackend $backend): array
    {
        $free = $total = null;

        try {
            if ($backend->isFilesystem()) {
                $root = $backend->root();

                if (! is_dir($root)) {
                    throw new RuntimeException("Root path does not exist or is not mounted: {$root}");
                }

                $free = @disk_free_space($root) ?: null;
                $total = @disk_total_space($root) ?: null;
            }

            $disk = $this->disk($backend);
            $probe = '.health/'.Str::uuid()->toString();
            $disk->put($probe, 'ok');
            $read = $disk->get($probe);
            $disk->delete($probe);

            if ($read !== 'ok') {
                throw new RuntimeException('Probe read-back did not match.');
            }

            $result = ['ok' => true, 'message' => 'Readable and writable', 'free_bytes' => $free ? (int) $free : null, 'total_bytes' => $total ? (int) $total : null];
        } catch (Throwable $e) {
            $result = ['ok' => false, 'message' => Str::limit($e->getMessage(), 300), 'free_bytes' => null, 'total_bytes' => null];
        }

        $backend->forceFill([
            'health_status' => $result['ok'] ? 'healthy' : 'failing',
            'health_message' => $result['message'],
            'health_checked_at' => now(),
        ])->saveQuietly();

        return $result;
    }

    /** Forget cached disk instances (after configuration changes). */
    public function flush(): void
    {
        $this->disks = [];
    }
}

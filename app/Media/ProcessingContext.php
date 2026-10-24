<?php

namespace App\Media;

use App\Enums\FileRole;
use App\Models\AssetFile;
use App\Models\AssetVersion;
use App\Storage\StorageManager;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Everything a processor needs: a private scratch directory, a local copy
 * of the original (under a generated name) and helpers that persist
 * derivatives to the version's storage backend.
 */
class ProcessingContext
{
    private int $lastReportedProgress = -1;

    public function __construct(
        public readonly AssetVersion $version,
        public readonly string $workDir,
        public readonly string $sourcePath,
        private readonly StorageManager $storage,
    ) {}

    public function disk(): Filesystem
    {
        return $this->storage->disk($this->version->storageBackend);
    }

    public function path(string $relative): string
    {
        $path = $this->workDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        File::ensureDirectoryExists(dirname($path));

        return $path;
    }

    public function timeout(): int
    {
        return (int) config('dam.processing.timeouts.'.$this->version->asset->media_type->value, 900);
    }

    /** Persist a derivative file and record it. */
    public function store(string $localPath, FileRole $role, string $mime, string $name, array $attributes = []): AssetFile
    {
        if (! is_file($localPath) || filesize($localPath) === 0) {
            throw new RuntimeException("Expected derivative {$name} was not produced.");
        }

        $target = $this->version->storagePrefix().'/derivatives/'.$name;
        $stream = fopen($localPath, 'rb');

        try {
            $this->disk()->writeStream($target, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $this->version->files()->create([
            'role' => $role,
            'storage_backend_id' => $this->version->storage_backend_id,
            'path' => $target,
            'mime' => $mime,
            'size_bytes' => filesize($localPath),
            'checksum_sha256' => hash_file('sha256', $localPath),
            ...$attributes,
        ]);
    }

    /**
     * Persist every file in a local directory (e.g. an HLS rendition set)
     * under derivatives/{name}/ and return the stored relative paths.
     *
     * @return list<string>
     */
    public function storeDirectory(string $localDir, string $name): array
    {
        $stored = [];
        $base = $this->version->storagePrefix().'/derivatives/'.$name;

        foreach (File::allFiles($localDir) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $stream = fopen($file->getPathname(), 'rb');

            try {
                $this->disk()->writeStream($base.'/'.$relative, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $stored[] = $base.'/'.$relative;
        }

        return $stored;
    }

    public function directorySize(string $localDir): int
    {
        return collect(File::allFiles($localDir))->sum(fn ($file) => $file->getSize());
    }

    /** Throttled progress reporting (writes only on whole-percent changes ≥ 2). */
    public function progress(string $stage, int $percent): void
    {
        if ($percent === 100 || $percent === 0 || abs($percent - $this->lastReportedProgress) >= 2) {
            $this->lastReportedProgress = $percent;
            $this->version->updateProgress($stage, $percent);
        }
    }

    public function mergeMetadata(array $metadata): void
    {
        $this->version->forceFill(['metadata' => array_merge($this->version->metadata ?? [], $metadata)])->saveQuietly();
    }
}

<?php

namespace App\Delivery;

use App\Models\AssetFile;
use App\Models\DigitalAsset;
use App\Models\StorageBackend;
use App\Storage\StorageManager;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Streams derivative files without ever revealing storage paths or
 * original filenames. Supports:
 *   - php:        Symfony BinaryFileResponse (HTTP Range) for filesystem backends
 *   - x-accel:    Nginx internal redirect (X-Accel-Redirect) for filesystem backends
 *   - x-sendfile: Apache mod_xsendfile for filesystem backends
 *   - S3/MinIO:   proxied stream with single-range support (never a bucket URL)
 */
class MediaResponder
{
    public function __construct(private readonly StorageManager $storage, private readonly MediaAccess $access) {}

    public function file(Request $request, AssetFile $file, ?string $relativePath = null, ?string $mime = null): \Symfony\Component\HttpFoundation\Response
    {
        $backend = $file->storageBackend;
        $path = $relativePath === null ? $file->path : $file->directory().'/'.$relativePath;
        $mime ??= $file->mime;
        $disk = $this->storage->disk($backend);

        if (! $disk->exists($path)) {
            throw new NotFoundHttpException;
        }

        $headers = $this->headers($mime);

        if ($backend->isFilesystem()) {
            return match (config('dam.delivery.driver')) {
                'x-accel' => response('', 200, $headers + ['X-Accel-Redirect' => $this->accelPath($backend, $path)]),
                'x-sendfile' => response('', 200, $headers + ['X-Sendfile' => $this->storage->absolutePath($backend, $path)]),
                default => $this->binary($this->storage->absolutePath($backend, $path), $headers),
            };
        }

        return $this->stream($request, $disk, $path, $headers);
    }

    /**
     * Serve an HLS playlist with every URI rewritten to a short-lived
     * signed URL for the same asset.
     */
    public function playlist(DigitalAsset $asset, AssetFile $master, string $relativePath): Response
    {
        $disk = $this->storage->disk($master->storageBackend);
        $path = $master->directory().'/'.$relativePath;

        if (! $disk->exists($path)) {
            throw new NotFoundHttpException;
        }

        $baseDir = trim(dirname($relativePath), '.');
        $lines = preg_split('/\r?\n/', (string) $disk->get($path));

        $rewritten = array_map(function (string $line) use ($asset, $master, $baseDir) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                return $line;
            }

            $target = ltrim(($baseDir !== '' ? $baseDir.'/' : '').$line, '/');

            return self::isAllowedHlsPath($target) ? $this->access->signedHlsUrl($asset, $master, $target) : '#';
        }, $lines);

        return response(implode("\n", $rewritten), 200, $this->headers('application/vnd.apple.mpegurl', cacheSeconds: 0));
    }

    public static function isAllowedHlsPath(string $path): bool
    {
        return (bool) preg_match('#^(?:\d{2,4}p/)?(?:master|index)\.m3u8$|^\d{2,4}p/seg_\d{5}\.ts$#', $path);
    }

    private function headers(string $mime, int $cacheSeconds = 300): array
    {
        return [
            'Content-Type' => $mime,
            // Inline only, and deliberately no filename.
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $cacheSeconds > 0 ? "private, max-age={$cacheSeconds}" : 'no-store',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Referrer-Policy' => 'same-origin',
        ];
    }

    private function binary(string $absolutePath, array $headers): BinaryFileResponse
    {
        $response = new BinaryFileResponse($absolutePath, 200, $headers, false, null, false, false);
        $response->headers->set('Content-Disposition', 'inline');

        return $response;
    }

    private function accelPath(StorageBackend $backend, string $path): string
    {
        $segments = array_map('rawurlencode', explode('/', ltrim($path, '/')));

        return rtrim(config('dam.delivery.x_accel_prefix'), '/').'/'.$backend->key.'/'.implode('/', $segments);
    }

    private function stream(Request $request, Filesystem $disk, string $path, array $headers): StreamedResponse
    {
        $size = $disk->size($path);
        $start = 0;
        $end = $size - 1;
        $status = 200;

        if (preg_match('/^bytes=(\d*)-(\d*)$/', (string) $request->header('Range'), $m) && ($m[1] !== '' || $m[2] !== '')) {
            if ($m[1] === '') {
                $start = max(0, $size - (int) $m[2]);
            } else {
                $start = (int) $m[1];
                $end = $m[2] !== '' ? min((int) $m[2], $size - 1) : $end;
            }

            if ($start > $end || $start >= $size) {
                return new StreamedResponse(fn () => null, 416, ['Content-Range' => "bytes */{$size}"]);
            }

            $status = 206;
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        $headers['Accept-Ranges'] = 'bytes';
        $headers['Content-Length'] = (string) ($end - $start + 1);

        return new StreamedResponse(function () use ($disk, $path, $start, $end) {
            $stream = $disk->readStream($path);

            try {
                // Object storage streams are not seekable: skip forward.
                if ($start > 0) {
                    $skip = $start;

                    while ($skip > 0 && ! feof($stream)) {
                        $skip -= strlen(fread($stream, min(1048576, $skip)));
                    }
                }

                $remaining = $end - $start + 1;

                while ($remaining > 0 && ! feof($stream)) {
                    $chunk = fread($stream, min(262144, $remaining));
                    $remaining -= strlen($chunk);
                    echo $chunk;
                    flush();
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, $status, $headers);
    }
}

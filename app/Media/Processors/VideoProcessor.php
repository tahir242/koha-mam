<?php

namespace App\Media\Processors;

use App\Enums\FileRole;
use App\Media\ProcessingContext;
use App\Media\Processors\Concerns\ProbesWithFfprobe;
use App\Media\ToolRunner;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Video → poster, thumbnail and an HLS (H.264/AAC) rendition ladder capped
 * at the source resolution. Segments are delivered only through signed,
 * authorised URLs; the original container is never served.
 */
class VideoProcessor implements MediaProcessor
{
    use ProbesWithFfprobe;

    public function __construct(private readonly ToolRunner $runner) {}

    public function process(ProcessingContext $context): void
    {
        $probe = $this->probe($context);
        $video = $this->firstStream($probe, 'video') ?? throw new RuntimeException('The file contains no video stream.');
        $audio = $this->firstStream($probe, 'audio');
        $duration = (float) ($probe['format']['duration'] ?? $video['duration'] ?? 0);
        $sourceHeight = (int) ($video['height'] ?? 0);

        if ($duration <= 0 || $sourceHeight <= 0) {
            throw new RuntimeException('Could not determine video duration or resolution.');
        }

        $context->mergeMetadata([
            'duration' => round($duration, 2),
            'width' => (int) ($video['width'] ?? 0),
            'height' => $sourceHeight,
            'video_codec' => $video['codec_name'] ?? null,
            'audio_codec' => $audio['codec_name'] ?? null,
        ]);

        $this->poster($context, $duration);
        $context->progress('transcoding', 8);

        $renditions = $this->renditions($sourceHeight);
        $hlsDir = $context->path('hls');
        File::ensureDirectoryExists($hlsDir);
        $config = config('dam.processing.video');
        $variants = [];
        $span = 85 / count($renditions);

        foreach ($renditions as $i => $rendition) {
            $height = $rendition['height'];
            $dir = $hlsDir.DIRECTORY_SEPARATOR."{$height}p";
            File::ensureDirectoryExists($dir);

            $arguments = [
                '-hide_banner', '-nostdin', '-y',
                '-i', $context->sourcePath,
                '-map', '0:v:0',
            ];

            if ($audio) {
                $arguments = [...$arguments, '-map', '0:a:0', '-c:a', 'aac', '-b:a', $rendition['audio_bitrate'], '-ac', '2'];
            }

            $arguments = [
                ...$arguments,
                '-c:v', 'libx264', '-preset', $config['preset'], '-profile:v', 'main', '-pix_fmt', 'yuv420p',
                '-crf', '23', '-maxrate', $rendition['video_bitrate'], '-bufsize', ((int) $rendition['video_bitrate'] * 2).'k',
                '-vf', "scale=-2:{$height}",
                '-g', '48', '-keyint_min', '48', '-sc_threshold', '0',
                '-threads', (string) config('dam.processing.threads'),
                '-f', 'hls', '-hls_time', (string) $config['hls_segment_seconds'], '-hls_playlist_type', 'vod',
                '-hls_segment_filename', $dir.DIRECTORY_SEPARATOR.'seg_%05d.ts',
                '-progress', 'pipe:1', '-nostats',
                $dir.DIRECTORY_SEPARATOR.'index.m3u8',
            ];

            $from = 8 + (int) ($i * $span);
            $this->runner->mustRun('ffmpeg', $arguments, $context->timeout(), null, $this->progressCallback($context, "transcoding {$height}p", $duration, $from, (int) ($from + $span)));

            $width = (int) (round(($video['width'] ?? 16) / max(1, $sourceHeight) * $height / 2) * 2);
            $bandwidth = ((int) $rendition['video_bitrate'] + ($audio ? (int) $rendition['audio_bitrate'] : 0)) * 1000;
            $variants[] = "#EXT-X-STREAM-INF:BANDWIDTH={$bandwidth},RESOLUTION={$width}x{$height}\n{$height}p/index.m3u8";
        }

        file_put_contents($hlsDir.DIRECTORY_SEPARATOR.'master.m3u8', "#EXTM3U\n#EXT-X-VERSION:3\n".implode("\n", $variants)."\n");

        $size = $context->directorySize($hlsDir);
        $context->storeDirectory($hlsDir, 'hls');

        $context->version->files()->create([
            'role' => FileRole::HlsMaster,
            'storage_backend_id' => $context->version->storage_backend_id,
            'path' => $context->version->storagePrefix().'/derivatives/hls/master.m3u8',
            'mime' => 'application/vnd.apple.mpegurl',
            'size_bytes' => $size,
            'metadata' => ['renditions' => array_column($renditions, 'height'), 'duration' => round($duration, 2)],
        ]);

        $context->progress('transcoding', 95);
    }

    public function requiredRoles(ProcessingContext $context): array
    {
        return [FileRole::HlsMaster, FileRole::Poster, FileRole::Thumbnail];
    }

    public function requiredTools(): array
    {
        return ['ffmpeg', 'ffprobe', 'magick'];
    }

    /**
     * @return list<array{height: int, video_bitrate: string, audio_bitrate: string}>
     */
    private function renditions(int $sourceHeight): array
    {
        $ladder = config('dam.processing.video.renditions');
        $selected = array_values(array_filter($ladder, fn ($r) => $r['height'] <= $sourceHeight));

        if ($selected === []) {
            // Source smaller than the lowest rung: keep its own (even) height.
            $selected = [array_merge($ladder[0], ['height' => max(2, $sourceHeight - $sourceHeight % 2)])];
        }

        return $selected;
    }

    private function poster(ProcessingContext $context, float $duration): void
    {
        $poster = $context->path('out/poster.jpg');
        $at = (string) round(min(10, $duration * 0.1), 2);

        $this->runner->mustRun('ffmpeg', ['-hide_banner', '-nostdin', '-y', '-ss', $at, '-i', $context->sourcePath, '-frames:v', '1', '-vf', 'scale=min(1280\,iw):-2', '-q:v', '3', $poster], 300);

        [$w, $h] = getimagesize($poster) ?: [null, null];
        $context->store($poster, FileRole::Poster, 'image/jpeg', 'poster.jpg', ['width' => $w, 'height' => $h]);

        $thumb = $context->path('out/thumbnail.webp');
        $size = config('dam.processing.image.thumbnail');
        $this->runner->mustRun('magick', [$poster, '-thumbnail', "{$size}x{$size}>", '-quality', '80', 'webp:'.$thumb], 120);
        [$w, $h] = getimagesize($thumb) ?: [null, null];
        $context->store($thumb, FileRole::Thumbnail, 'image/webp', 'thumbnail.webp', ['width' => $w, 'height' => $h]);
    }
}

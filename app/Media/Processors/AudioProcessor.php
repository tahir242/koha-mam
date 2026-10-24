<?php

namespace App\Media\Processors;

use App\Enums\FileRole;
use App\Media\ProcessingContext;
use App\Media\Processors\Concerns\ProbesWithFfprobe;
use App\Media\ToolRunner;
use RuntimeException;

/**
 * Audio → AAC in an MP4 container with fast-start, playable by every
 * current browser. Delivered with HTTP range support through the
 * authorised media endpoint.
 */
class AudioProcessor implements MediaProcessor
{
    use ProbesWithFfprobe;

    public function __construct(private readonly ToolRunner $runner) {}

    public function process(ProcessingContext $context): void
    {
        $probe = $this->probe($context);
        $audio = $this->firstStream($probe, 'audio') ?? throw new RuntimeException('The file contains no audio stream.');
        $duration = (float) ($probe['format']['duration'] ?? $audio['duration'] ?? 0);

        $context->mergeMetadata([
            'duration' => round($duration, 2),
            'audio_codec' => $audio['codec_name'] ?? null,
            'sample_rate' => isset($audio['sample_rate']) ? (int) $audio['sample_rate'] : null,
            'channels' => $audio['channels'] ?? null,
        ]);

        $out = $context->path('out/audio.m4a');

        $this->runner->mustRun('ffmpeg', [
            '-hide_banner', '-nostdin', '-y',
            '-i', $context->sourcePath,
            '-map', '0:a:0', '-vn', '-map_metadata', '-1',
            '-c:a', 'aac', '-b:a', config('dam.processing.audio.bitrate'), '-ac', '2',
            '-movflags', '+faststart',
            '-threads', (string) config('dam.processing.threads'),
            '-progress', 'pipe:1', '-nostats',
            $out,
        ], $context->timeout(), null, $this->progressCallback($context, 'transcoding', $duration, 5, 95));

        $context->store($out, FileRole::Audio, 'audio/mp4', 'audio.m4a', ['metadata' => ['duration' => round($duration, 2)]]);
    }

    public function requiredRoles(ProcessingContext $context): array
    {
        return [FileRole::Audio];
    }

    public function requiredTools(): array
    {
        return ['ffmpeg', 'ffprobe'];
    }
}

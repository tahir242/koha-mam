<?php

namespace App\Media\Processors\Concerns;

use App\Media\ProcessingContext;
use RuntimeException;

trait ProbesWithFfprobe
{
    protected function probe(ProcessingContext $context): array
    {
        $process = $this->runner->mustRun('ffprobe', ['-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $context->sourcePath], 120);
        $data = json_decode($process->getOutput(), true);

        if (! is_array($data) || empty($data['streams'])) {
            throw new RuntimeException('ffprobe found no media streams.');
        }

        return $data;
    }

    protected function firstStream(array $probe, string $type): ?array
    {
        foreach ($probe['streams'] as $stream) {
            if (($stream['codec_type'] ?? null) === $type && empty($stream['disposition']['attached_pic'])) {
                return $stream;
            }
        }

        return null;
    }

    /**
     * Callback for `ffmpeg -progress pipe:1` that maps encoded time to a
     * percentage range of the overall job.
     */
    protected function progressCallback(ProcessingContext $context, string $stage, float $duration, int $from, int $to): callable
    {
        return function (string $type, string $buffer) use ($context, $stage, $duration, $from, $to) {
            if ($duration <= 0 || ! preg_match_all('/out_time_(?:us|ms)=(\d+)/', $buffer, $m)) {
                return;
            }

            $seconds = ((int) end($m[1])) / 1_000_000;
            $ratio = min(1, $seconds / $duration);
            $context->progress($stage, $from + (int) (($to - $from) * $ratio));
        };
    }
}

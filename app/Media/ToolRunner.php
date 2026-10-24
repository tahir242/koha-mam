<?php

namespace App\Media;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Executes external media tools (FFmpeg, Poppler, ImageMagick, LibreOffice,
 * ClamAV) with argument arrays — never through a shell — so filenames and
 * other values can never be interpreted as shell syntax. User-supplied
 * filenames are never passed at all: working files use generated names.
 */
class ToolRunner
{
    /**
     * @param  list<string>  $arguments
     * @param  callable(string, string): void|null  $onOutput
     */
    public function run(string $tool, array $arguments, int $timeout, ?string $cwd = null, ?callable $onOutput = null, array $env = []): Process
    {
        $process = new Process($this->command($tool, $arguments), $cwd, $env + ['LC_ALL' => 'C'], null, $timeout);

        try {
            $process->run($onOutput);
        } catch (ProcessTimedOutException) {
            throw new RuntimeException("{$tool} timed out after {$timeout} seconds.");
        }

        return $process;
    }

    /**
     * @param  list<string>  $arguments
     */
    public function mustRun(string $tool, array $arguments, int $timeout, ?string $cwd = null, ?callable $onOutput = null, array $env = []): Process
    {
        $process = $this->run($tool, $arguments, $timeout, $cwd, $onOutput, $env);

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput()) ?: trim($process->getOutput());

            throw new RuntimeException(sprintf('%s failed (exit %s): %s', $tool, $process->getExitCode(), mb_substr($error, -800)));
        }

        return $process;
    }

    public function binary(string $tool): ?string
    {
        return config("dam.processing.binaries.{$tool}");
    }

    public function isAvailable(string $tool): bool
    {
        $binary = $this->binary($tool);

        if (! $binary) {
            return false;
        }

        return is_file($binary) || (new ExecutableFinder)->find($binary) !== null;
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    private function command(string $tool, array $arguments): array
    {
        $binary = $this->binary($tool) ?? $tool;
        $command = [$binary, ...array_map('strval', $arguments)];

        $nice = config('dam.processing.nice');

        if ($nice !== null && $nice !== '' && DIRECTORY_SEPARATOR === '/' && $tool !== 'clamdscan') {
            $command = ['nice', '-n', (string) (int) $nice, ...$command];
        }

        return $command;
    }
}

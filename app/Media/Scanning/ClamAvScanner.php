<?php

namespace App\Media\Scanning;

use App\Media\ToolRunner;

/**
 * ClamAV via clamdscan (talks to the clamd daemon). Exit codes:
 * 0 = clean, 1 = infected, 2 = error.
 */
class ClamAvScanner implements MalwareScanner
{
    public function __construct(private readonly ToolRunner $runner) {}

    public function scan(string $absolutePath): ScanResult
    {
        $process = $this->runner->run('clamdscan', ['--no-summary', '--fdpass', '--stdout', $absolutePath], (int) config('dam.scanning.timeout'));

        return match ($process->getExitCode()) {
            0 => ScanResult::clean($this->name()),
            1 => ScanResult::infected($this->name(), $this->signature($process->getOutput())),
            default => throw new ScanFailedException('ClamAV scan failed: '.mb_substr(trim($process->getErrorOutput().' '.$process->getOutput()), 0, 300)),
        };
    }

    public function name(): string
    {
        return 'clamav';
    }

    private function signature(string $output): string
    {
        return preg_match('/:\s*(.+?)\s+FOUND/', $output, $m) ? $m[1] : 'unknown';
    }
}

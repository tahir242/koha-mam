<?php

namespace App\Media\Scanning;

/**
 * Used only when scanning is explicitly disabled (DAM_MALWARE_SCANNER=none).
 * If DAM_MALWARE_SCAN_REQUIRED is true, uploads are refused instead.
 */
class NullScanner implements MalwareScanner
{
    public function scan(string $absolutePath): ScanResult
    {
        if (config('dam.scanning.required')) {
            throw new ScanFailedException('Malware scanning is required but no scanner is configured.');
        }

        return ScanResult::clean($this->name());
    }

    public function name(): string
    {
        return 'none';
    }
}

<?php

namespace App\Media\Scanning;

final class ScanResult
{
    public function __construct(public readonly bool $clean, public readonly string $scanner, public readonly ?string $signature = null) {}

    public static function clean(string $scanner): self
    {
        return new self(true, $scanner);
    }

    public static function infected(string $scanner, string $signature): self
    {
        return new self(false, $scanner, $signature);
    }
}

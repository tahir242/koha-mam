<?php

namespace App\Media\Processors;

use App\Enums\FileRole;
use App\Media\ProcessingContext;

interface MediaProcessor
{
    /**
     * Generate viewing derivatives. Must throw on any failure; partial
     * output is discarded by the caller.
     */
    public function process(ProcessingContext $context): void;

    /**
     * Roles that must exist after processing for the version to be viewable.
     *
     * @return list<FileRole>
     */
    public function requiredRoles(ProcessingContext $context): array;

    /**
     * @return list<string> tool keys from dam.processing.binaries
     */
    public function requiredTools(): array;
}

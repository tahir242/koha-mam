<?php

namespace App\Koha\Exceptions;

use RuntimeException;

/**
 * Base class for Koha REST API failures. `isTransient()` drives retry
 * decisions in the publication outbox.
 */
class KohaException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function isTransient(): bool
    {
        return false;
    }

    public function kind(): string
    {
        return 'koha_error';
    }
}

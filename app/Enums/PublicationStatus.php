<?php

namespace App\Enums;

enum PublicationStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    /** The write happened but read-back verification found unexpected differences. */
    case NeedsReview = 'needs_review';
    /** Replaced by a newer operation for the same asset. */
    case Superseded = 'superseded';

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Running], true);
    }
}

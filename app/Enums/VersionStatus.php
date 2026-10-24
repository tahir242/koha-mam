<?php

namespace App\Enums;

enum VersionStatus: string
{
    case Received = 'received';
    case Validating = 'validating';
    case Scanning = 'scanning';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Superseded = 'superseded';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Ready, self::Failed, self::Superseded], true);
    }
}

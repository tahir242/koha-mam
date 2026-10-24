<?php

namespace App\Enums;

enum MediaType: string
{
    case Document = 'document';
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Document => 'Document',
            self::Image => 'Image',
            self::Video => 'Video',
            self::Audio => 'Audio',
            self::Other => 'Other',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Document => 'document-text',
            self::Image => 'photo',
            self::Video => 'film',
            self::Audio => 'musical-note',
            self::Other => 'archive-box',
        };
    }
}

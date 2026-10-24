<?php

namespace App\Enums;

enum FileRole: string
{
    /** Archival original. Never delivered to the public. */
    case Original = 'original';
    case ViewerPdf = 'viewer_pdf';
    case PageImage = 'page_image';
    case Thumbnail = 'thumbnail';
    case Poster = 'poster';
    case ImageLarge = 'image_large';
    case ImageMedium = 'image_medium';
    case HlsMaster = 'hls_master';
    case Audio = 'audio';

    /** Roles that may be delivered by the public viewer. */
    public function isPublicDerivative(): bool
    {
        return $this !== self::Original;
    }
}

<?php

namespace App\Media;

use finfo;
use ZipArchive;

/**
 * Content-based type detection. The client-supplied MIME type is recorded
 * but never trusted.
 */
class FileInspector
{
    private const OOXML = [
        'docx' => ['word/document.xml', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xlsx' => ['xl/workbook.xml', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'pptx' => ['ppt/presentation.xml', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
    ];

    public function detectMime(string $path, string $extension): string
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        $extension = strtolower($extension);

        // Office Open XML and EPUB are ZIP containers; confirm by structure.
        if (in_array($mime, ['application/zip', 'application/octet-stream', 'application/x-zip-compressed'], true)) {
            return $this->detectZipContainer($path, $extension) ?? $mime;
        }

        // Some libmagic builds report SVG as text/xml or text/plain.
        if (in_array($mime, ['text/xml', 'application/xml', 'text/plain', 'text/html'], true) && $extension === 'svg' && $this->looksLikeSvg($path)) {
            return 'image/svg+xml';
        }

        return $mime;
    }

    private function detectZipContainer(string $path, string $extension): ?string
    {
        if (! class_exists(ZipArchive::class)) {
            return null;
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            if ($extension === 'epub') {
                return trim((string) $zip->getFromName('mimetype')) === 'application/epub+zip' ? 'application/epub+zip' : null;
            }

            if (isset(self::OOXML[$extension]) && $zip->locateName('[Content_Types].xml') !== false && $zip->locateName(self::OOXML[$extension][0]) !== false) {
                return self::OOXML[$extension][1];
            }

            return null;
        } finally {
            $zip->close();
        }
    }

    private function looksLikeSvg(string $path): bool
    {
        $head = (string) file_get_contents($path, false, null, 0, 4096);

        return (bool) preg_match('/<svg[\s>]/i', $head);
    }
}

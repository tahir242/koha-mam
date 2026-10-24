<?php

namespace App\Koha;

/**
 * The MAM's link in a Koha item's URI field (items.uri, MARC21 952$u).
 *
 * Each item gets one stable link, {public_url}/item/{itemnumber}, however
 * many files are attached: Koha shows a single URI with its link text
 * (URLLinkText) in the staff and OPAC holdings tables. Koha also accepts
 * several URIs separated by " | ", so a URL catalogued by hand is kept and
 * the MAM link is added after it. Only the MAM's own link is ever removed.
 */
class ManagedItemUri
{
    public const SEPARATOR = ' | ';

    public function url(int $itemId): string
    {
        return rtrim(config('dam.public_url'), '/').'/item/'.$itemId;
    }

    public function has(?string $uri, int $itemId): bool
    {
        return in_array(strtolower($this->url($itemId)), array_map('strtolower', $this->split($uri)), true);
    }

    public function with(?string $uri, int $itemId): string
    {
        $parts = $this->split($uri);

        if (! $this->has($uri, $itemId)) {
            $parts[] = $this->url($itemId);
        }

        return implode(self::SEPARATOR, $parts);
    }

    public function without(?string $uri, int $itemId): ?string
    {
        $ours = strtolower($this->url($itemId));
        $parts = array_values(array_filter($this->split($uri), fn (string $part) => strtolower($part) !== $ours));

        return $parts === [] ? null : implode(self::SEPARATOR, $parts);
    }

    /** @return list<string> */
    private function split(?string $uri): array
    {
        return array_values(array_filter(array_map('trim', explode('|', (string) $uri)), fn (string $part) => $part !== ''));
    }
}

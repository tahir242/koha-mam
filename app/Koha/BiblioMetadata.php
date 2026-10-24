<?php

namespace App\Koha;

use App\Koha\Marc\MarcRecord;
use Carbon\CarbonImmutable;

/**
 * Display metadata for a Koha bibliographic record. Values come from the
 * MARC21 record first, falling back to Koha's JSON representation.
 */
final class BiblioMetadata
{
    public function __construct(
        public readonly int $biblioId,
        public readonly string $title,
        public readonly ?string $subtitle,
        public readonly ?string $author,
        public readonly ?string $edition,
        public readonly ?string $isbn,
        public readonly ?string $publisher,
        public readonly ?string $publicationPlace,
        public readonly ?string $publicationYear,
        public readonly ?string $frameworkId,
        public readonly CarbonImmutable $fetchedAt,
        public readonly bool $fromCache = false,
    ) {}

    public static function fromKoha(int $biblioId, array $json, ?MarcRecord $marc): self
    {
        $clean = fn (?string $value) => $value === null ? null : (trim(preg_replace('/\s*[\/:;,.=]\s*$/u', '', trim($value))) ?: null);

        $publisherTag = $marc?->subfield('264', 'b') !== null ? '264' : '260';

        return new self(
            biblioId: $biblioId,
            title: $clean($marc?->subfield('245', 'a') ?? ($json['title'] ?? null)) ?? 'Untitled record',
            subtitle: $clean($marc?->subfield('245', 'b') ?? ($json['subtitle'] ?? null)),
            author: $clean($marc?->subfield('100', 'a') ?? $marc?->subfield('110', 'a') ?? $marc?->subfield('245', 'c') ?? ($json['author'] ?? null)),
            // Editions keep their final period ("2nd ed.").
            edition: ($e = $marc?->subfield('250', 'a') ?? ($json['edition_statement'] ?? null)) === null ? null : (trim(preg_replace('/\s*[\/:;,=]\s*$/u', '', trim($e))) ?: null),
            isbn: self::isbn($marc?->subfield('020', 'a') ?? ($json['isbn'] ?? null)),
            publisher: $clean($marc?->subfield($publisherTag, 'b') ?? ($json['publisher'] ?? null)),
            publicationPlace: $clean($marc?->subfield($publisherTag, 'a') ?? ($json['publication_place'] ?? null)),
            publicationYear: $clean($marc?->subfield($publisherTag, 'c') ?? (isset($json['publication_year']) ? (string) $json['publication_year'] : ($json['copyright_date'] ?? null))),
            frameworkId: $json['framework_id'] ?? null,
            fetchedAt: CarbonImmutable::now(),
        );
    }

    public static function fromArray(array $data, bool $fromCache = false): self
    {
        return new self(
            biblioId: (int) $data['biblio_id'],
            title: $data['title'],
            subtitle: $data['subtitle'] ?? null,
            author: $data['author'] ?? null,
            edition: $data['edition'] ?? null,
            isbn: $data['isbn'] ?? null,
            publisher: $data['publisher'] ?? null,
            publicationPlace: $data['publication_place'] ?? null,
            publicationYear: $data['publication_year'] ?? null,
            frameworkId: $data['framework_id'] ?? null,
            fetchedAt: CarbonImmutable::parse($data['fetched_at']),
            fromCache: $fromCache,
        );
    }

    public function toArray(): array
    {
        return [
            'biblio_id' => $this->biblioId,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'author' => $this->author,
            'edition' => $this->edition,
            'isbn' => $this->isbn,
            'publisher' => $this->publisher,
            'publication_place' => $this->publicationPlace,
            'publication_year' => $this->publicationYear,
            'framework_id' => $this->frameworkId,
            'fetched_at' => $this->fetchedAt->toIso8601String(),
        ];
    }

    public function fullTitle(): string
    {
        return $this->subtitle ? "{$this->title}: {$this->subtitle}" : $this->title;
    }

    public function publication(): ?string
    {
        $parts = array_filter([$this->publicationPlace, $this->publisher, $this->publicationYear]);

        return $parts ? implode(', ', $parts) : null;
    }

    private static function isbn(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_match('/[0-9Xx][0-9Xx\-]{8,16}/', $value, $m) ? str_replace('-', '', $m[0]) : null;
    }
}

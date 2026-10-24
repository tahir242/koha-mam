<?php

namespace App\Koha;

/**
 * Display copy of a Koha item. Items are always identified and labelled by
 * their itemnumber; the barcode is optional in Koha (an item may be saved
 * before one is assigned), so it is only shown as a detail.
 */
final class ItemMetadata
{
    /**
     * Normalise Koha's item JSON (GET /api/v1/items/{id}) into the stored snapshot.
     *
     * @return array{item_id: int, biblio_id: int, barcode: ?string, copy_number: ?string, callnumber: ?string, home_library_id: ?string}
     */
    public static function fromKoha(int $itemId, array $json): array
    {
        $text = fn (string $key) => is_scalar($json[$key] ?? null) && trim((string) $json[$key]) !== '' ? mb_substr(trim((string) $json[$key]), 0, 255) : null;

        return [
            'item_id' => $itemId,
            'biblio_id' => (int) ($json['biblio_id'] ?? 0),
            'barcode' => $text('external_id'),
            'copy_number' => $text('copy_number'),
            'callnumber' => $text('callnumber'),
            'home_library_id' => $text('home_library_id'),
        ];
    }

    /** The item number: the one identifier every saved item has and that never changes. */
    public static function label(int $itemId): string
    {
        return 'Item #'.$itemId;
    }

    /** Secondary details for display, e.g. "Barcode 39999 · Copy 2 · QA76 .K6 (MAIN)"; null when nothing is known. */
    public static function details(?array $snapshot): ?string
    {
        $snapshot ??= [];
        $parts = array_filter([
            ! empty($snapshot['barcode']) ? 'Barcode '.$snapshot['barcode'] : null,
            ! empty($snapshot['copy_number']) ? 'Copy '.$snapshot['copy_number'] : null,
            ! empty($snapshot['callnumber']) ? $snapshot['callnumber'].(! empty($snapshot['home_library_id']) ? ' ('.$snapshot['home_library_id'].')' : '') : null,
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }
}

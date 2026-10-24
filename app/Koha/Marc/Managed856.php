<?php

namespace App\Koha\Marc;

use App\Enums\AccessLevel;
use App\Models\DigitalAsset;

/**
 * Builds and recognises the 856 fields owned by this application.
 *
 * A field is "managed" when its $u is a MAM viewer URL
 * ({public_url}/view/{uuid}, or a configured legacy prefix). Matching on the
 * URL avoids adding private marker subfields to the catalogue while still
 * letting the MAM find, replace and remove exactly its own links. Fields
 * pointing anywhere else are never modified.
 */
class Managed856
{
    public function build(DigitalAsset $asset): DataField
    {
        $marc = config('dam.publishing.marc');
        $ind2 = $marc['relationship_indicators'][$asset->marc_relationship->value] ?? ' ';
        $restricted = $asset->access_level === AccessLevel::Restricted;

        $subfields = [];

        if ($materials = $marc['materials'][$asset->media_type->value] ?? null) {
            $subfields[] = ['3', $materials];
        }

        $subfields[] = ['u', $asset->viewerUrl()];
        $subfields[] = ['y', $asset->linkText()];

        $note = $asset->public_note ?: ($restricted ? $marc['restricted_note'] : null);

        if ($note) {
            $subfields[] = ['z', $note];
        }

        // $7 Access status: 0 = open access, 1 = restricted access.
        $subfields[] = ['7', $restricted ? '1' : '0'];

        // First indicator 4 = HTTP access method.
        return new DataField('856', '4', $ind2, $subfields);
    }

    public function isManaged(DataField $field): bool
    {
        if ($field->tag !== '856') {
            return false;
        }

        foreach ($field->all('u') as $url) {
            if ($this->assetUuidFromUrl($url) !== null) {
                return true;
            }
        }

        return false;
    }

    public function isManagedFor(DataField $field, DigitalAsset $asset): bool
    {
        foreach ($field->all('u') as $url) {
            if ($this->assetUuidFromUrl($url) === strtolower($asset->uuid)) {
                return true;
            }
        }

        return false;
    }

    public function assetUuidFromUrl(string $url): ?string
    {
        $prefixes = array_merge([config('dam.public_url')], config('dam.legacy_viewer_prefixes', []));

        foreach ($prefixes as $prefix) {
            $prefix = rtrim($prefix, '/').'/view/';

            if (str_starts_with(strtolower($url), strtolower($prefix))) {
                $uuid = strtolower(substr($url, strlen($prefix)));

                if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
                    return $uuid;
                }
            }
        }

        return null;
    }

    /**
     * Ensure the record has exactly one managed 856 for the asset with the
     * expected content. Returns true when the record was changed.
     */
    public function apply(MarcRecord $record, DigitalAsset $asset): bool
    {
        $expected = $this->build($asset);
        $existing = array_values(array_filter($record->dataFields('856'), fn (DataField $f) => $this->isManagedFor($f, $asset)));

        if (count($existing) === 1 && $existing[0]->fingerprint() === $expected->fingerprint()) {
            return false;
        }

        $record->removeDataFields('856', fn (DataField $f) => $this->isManagedFor($f, $asset));
        $record->addDataField($expected);

        return true;
    }

    /** Remove the asset's managed 856. Returns true when the record was changed. */
    public function remove(MarcRecord $record, DigitalAsset $asset): bool
    {
        return $record->removeDataFields('856', fn (DataField $f) => $this->isManagedFor($f, $asset)) > 0;
    }

    /** Verify the record contains exactly the expected managed 856 for the asset. */
    public function verifyPresent(MarcRecord $record, DigitalAsset $asset): bool
    {
        $fields = array_values(array_filter($record->dataFields('856'), fn (DataField $f) => $this->isManagedFor($f, $asset)));

        return count($fields) === 1 && $fields[0]->fingerprint() === $this->build($asset)->fingerprint();
    }

    public function verifyAbsent(MarcRecord $record, DigitalAsset $asset): bool
    {
        return array_filter($record->dataFields('856'), fn (DataField $f) => $this->isManagedFor($f, $asset)) === [];
    }
}

<?php

namespace App\Koha\Marc;

use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;

/**
 * Minimal MARCXML wrapper built on DOM. It edits only the datafields it is
 * asked to touch and serialises every other node unchanged, so existing
 * cataloguing data (including unrelated 856 fields, ordering and
 * indicators) is preserved byte-for-byte at the element level.
 */
class MarcRecord
{
    public const NS = 'http://www.loc.gov/MARC21/slim';

    private DOMDocument $doc;

    private DOMElement $record;

    private DOMXPath $xpath;

    private function __construct(DOMDocument $doc, DOMElement $record)
    {
        $this->doc = $doc;
        $this->record = $record;
        $this->xpath = new DOMXPath($doc);
        $this->xpath->registerNamespace('m', self::NS);
    }

    public static function fromXml(string $xml): self
    {
        $doc = new DOMDocument;
        $doc->preserveWhiteSpace = true;

        // LIBXML_NONET blocks network access; DTD loading and entity
        // substitution are not enabled, preventing XXE.
        if (trim($xml) === '' || ! @$doc->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)) {
            throw new InvalidArgumentException('Koha returned an invalid MARCXML document.');
        }

        if ($doc->doctype !== null) {
            throw new InvalidArgumentException('MARCXML with a DOCTYPE is not accepted.');
        }

        $records = $doc->getElementsByTagNameNS(self::NS, 'record');
        $record = $records->length ? $records->item(0) : null;

        if (! $record instanceof DOMElement) {
            // Some serialisations omit the namespace.
            $plain = $doc->getElementsByTagName('record');
            $record = $plain->length ? $plain->item(0) : null;
        }

        if (! $record instanceof DOMElement) {
            throw new InvalidArgumentException('MARCXML does not contain a <record> element.');
        }

        return new self($doc, $record);
    }

    public function toXml(): string
    {
        return $this->doc->saveXML();
    }

    /** Stable hash of the record content (whitespace-insensitive). */
    public function hash(): string
    {
        return hash('sha256', $this->doc->C14N());
    }

    public function controlField(string $tag): ?string
    {
        foreach ($this->childElements('controlfield') as $field) {
            if ($field->getAttribute('tag') === $tag) {
                return $field->textContent;
            }
        }

        return null;
    }

    /**
     * @return list<DataField>
     */
    public function dataFields(?string $tag = null): array
    {
        $fields = [];

        foreach ($this->childElements('datafield') as $element) {
            if ($tag === null || $element->getAttribute('tag') === $tag) {
                $fields[] = DataField::fromElement($element);
            }
        }

        return $fields;
    }

    /** First subfield value of the first field with the tag. */
    public function subfield(string $tag, string $code): ?string
    {
        foreach ($this->dataFields($tag) as $field) {
            if (($value = $field->first($code)) !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Remove every datafield with the tag for which the predicate is true.
     *
     * @param  callable(DataField): bool  $predicate
     * @return int number of removed fields
     */
    public function removeDataFields(string $tag, callable $predicate): int
    {
        $removed = 0;

        foreach ($this->childElements('datafield') as $element) {
            if ($element->getAttribute('tag') === $tag && $predicate(DataField::fromElement($element))) {
                $this->removeWithWhitespace($element);
                $removed++;
            }
        }

        return $removed;
    }

    /** Insert a datafield in tag order (after the last field with tag <= new tag). */
    public function addDataField(DataField $field): void
    {
        $element = $this->createElement('datafield');
        $element->setAttribute('tag', $field->tag);
        $element->setAttribute('ind1', $field->ind1);
        $element->setAttribute('ind2', $field->ind2);

        foreach ($field->subfields as [$code, $value]) {
            $sub = $this->createElement('subfield');
            $sub->setAttribute('code', $code);
            $sub->appendChild($this->doc->createTextNode($value));
            $element->appendChild($sub);
        }

        $after = null;

        foreach ($this->childElements(null) as $child) {
            $tag = $child->localName === 'leader' ? '000' : $child->getAttribute('tag');

            if (strcmp($tag, $field->tag) <= 0) {
                $after = $child;
            }
        }

        if ($after === null) {
            $this->record->insertBefore($element, $this->record->firstChild);
        } elseif ($after->nextSibling) {
            $this->record->insertBefore($element, $after->nextSibling);
        } else {
            $this->record->appendChild($element);
        }
    }

    /**
     * Canonical list of fields used to verify that a write preserved
     * everything except the expected change.
     *
     * @param  list<string>  $ignoreTags
     * @return list<string>
     */
    public function fingerprints(array $ignoreTags = []): array
    {
        $prints = [];

        foreach ($this->childElements(null) as $child) {
            $tag = $child->localName === 'leader' ? 'LDR' : $child->getAttribute('tag');

            if ($tag === 'LDR' || in_array($tag, $ignoreTags, true)) {
                continue;
            }

            $prints[] = $child->localName === 'controlfield'
                ? $tag.'|'.$child->textContent
                : DataField::fromElement($child)->fingerprint();
        }

        sort($prints);

        return $prints;
    }

    /**
     * @return list<DOMElement>
     */
    private function childElements(?string $localName): array
    {
        $out = [];

        foreach ($this->record->childNodes as $node) {
            if ($node instanceof DOMElement && ($localName === null || $node->localName === $localName)) {
                $out[] = $node;
            }
        }

        return $out;
    }

    private function createElement(string $name): DOMElement
    {
        $ns = $this->record->namespaceURI;
        $prefix = $this->record->prefix;

        return $ns ? $this->doc->createElementNS($ns, $prefix ? "{$prefix}:{$name}" : $name) : $this->doc->createElement($name);
    }

    private function removeWithWhitespace(DOMElement $element): void
    {
        $previous = $element->previousSibling;

        if ($previous && $previous->nodeType === XML_TEXT_NODE && trim($previous->textContent) === '') {
            $previous->parentNode->removeChild($previous);
        }

        $element->parentNode->removeChild($element);
    }
}

<?php

namespace App\Koha\Marc;

use DOMElement;

final class DataField
{
    /**
     * @param  list<array{0: string, 1: string}>  $subfields  ordered [code, value] pairs
     */
    public function __construct(
        public readonly string $tag,
        public readonly string $ind1,
        public readonly string $ind2,
        public readonly array $subfields,
    ) {}

    public static function fromElement(DOMElement $element): self
    {
        $subfields = [];

        foreach ($element->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === 'subfield') {
                $subfields[] = [$node->getAttribute('code'), $node->textContent];
            }
        }

        return new self(
            $element->getAttribute('tag'),
            self::indicator($element->getAttribute('ind1')),
            self::indicator($element->getAttribute('ind2')),
            $subfields,
        );
    }

    public function first(string $code): ?string
    {
        foreach ($this->subfields as [$c, $value]) {
            if ($c === $code) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function all(string $code): array
    {
        return array_values(array_map(fn ($s) => $s[1], array_filter($this->subfields, fn ($s) => $s[0] === $code)));
    }

    public function fingerprint(): string
    {
        return $this->tag.'|'.$this->ind1.$this->ind2.'|'.implode("\x1F", array_map(fn ($s) => $s[0].$s[1], $this->subfields));
    }

    public function toArray(): array
    {
        return ['tag' => $this->tag, 'ind1' => $this->ind1, 'ind2' => $this->ind2, 'subfields' => $this->subfields];
    }

    private static function indicator(string $value): string
    {
        return $value === '' ? ' ' : $value;
    }
}

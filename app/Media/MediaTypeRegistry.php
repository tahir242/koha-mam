<?php

namespace App\Media;

use App\Enums\MediaType;
use App\Media\Processors\MediaProcessor;

/**
 * Pluggable registry mapping (detected MIME, extension) pairs to media types
 * and processing strategies. Configured in config/dam.php "media_types".
 */
class MediaTypeRegistry
{
    public function __construct(private readonly array $types) {}

    public static function fromConfig(): self
    {
        return new self(config('dam.media_types'));
    }

    /**
     * Resolve the media type for content-detected MIME + extension. Both
     * must agree with the allowlist; returns null when unsupported.
     */
    public function resolve(string $detectedMime, string $extension): ?MediaType
    {
        $extension = strtolower($extension);

        foreach ($this->types as $type => $definition) {
            $allowed = $definition['formats'][$detectedMime] ?? null;

            if ($allowed !== null && in_array($extension, $allowed, true)) {
                return MediaType::from($type);
            }
        }

        return null;
    }

    public function processorFor(MediaType $type): ?MediaProcessor
    {
        $class = $this->types[$type->value]['processor'] ?? null;

        return $class ? app($class) : null;
    }

    public function queueFor(MediaType $type): string
    {
        return $this->types[$type->value]['queue'] ?? 'default';
    }

    /**
     * @return list<string>
     */
    public function allowedExtensions(): array
    {
        return collect($this->types)
            ->flatMap(fn (array $definition) => collect($definition['formats'])->flatten())
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array<string, list<string>> media type => extensions
     */
    public function extensionsByType(): array
    {
        return collect($this->types)
            ->map(fn (array $definition) => collect($definition['formats'])->flatten()->unique()->sort()->values()->all())
            ->all();
    }
}

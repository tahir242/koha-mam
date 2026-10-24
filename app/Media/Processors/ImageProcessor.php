<?php

namespace App\Media\Processors;

use App\Enums\FileRole;
use App\Media\ProcessingContext;
use App\Media\ToolRunner;
use enshrined\svgSanitize\Sanitizer;
use RuntimeException;

/**
 * Images: dimension check (decompression-bomb guard), SVG sanitisation and
 * rasterisation, then WebP derivatives at configured sizes. The archival
 * original is never served; SVG is never delivered as SVG.
 */
class ImageProcessor implements MediaProcessor
{
    public function __construct(private readonly ToolRunner $runner) {}

    public function process(ProcessingContext $context): void
    {
        $config = config('dam.processing.image');
        $source = $context->sourcePath;

        if ($context->version->detected_mime === 'image/svg+xml') {
            $source = $this->rasteriseSvg($context);
        }

        [$width, $height] = $this->dimensions($context, $source);

        if ($width * $height > $config['max_megapixels'] * 1_000_000) {
            throw new RuntimeException(sprintf('Image is %.0f megapixels; the limit is %d.', $width * $height / 1_000_000, $config['max_megapixels']));
        }

        $context->mergeMetadata(['width' => $width, 'height' => $height]);
        $context->progress('derivatives', 20);

        $variants = [
            [FileRole::ImageLarge, $config['large'], 'large.webp', '-resize'],
            [FileRole::ImageMedium, $config['medium'], 'medium.webp', '-resize'],
            [FileRole::Thumbnail, $config['thumbnail'], 'thumbnail.webp', '-thumbnail'],
        ];

        foreach ($variants as $i => [$role, $size, $name, $op]) {
            $out = $context->path('out/'.$name);

            $this->runner->mustRun('magick', [
                ...$this->limits(),
                $source.'[0]',
                '-auto-orient',
                '-strip',
                '-colorspace', 'sRGB',
                $op, "{$size}x{$size}>",
                '-quality', '82',
                'webp:'.$out,
            ], $context->timeout());

            [$w, $h] = getimagesize($out) ?: [null, null];
            $context->store($out, $role, 'image/webp', $name, ['width' => $w, 'height' => $h]);
            $context->progress('derivatives', 20 + (int) (($i + 1) / count($variants) * 75));
        }
    }

    public function requiredRoles(ProcessingContext $context): array
    {
        return [FileRole::ImageLarge, FileRole::ImageMedium, FileRole::Thumbnail];
    }

    public function requiredTools(): array
    {
        return ['magick'];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function dimensions(ProcessingContext $context, string $path): array
    {
        $size = @getimagesize($path);

        if ($size && $size[0] > 0 && $size[1] > 0) {
            return [$size[0], $size[1]];
        }

        $process = $this->runner->mustRun('magick', [...$this->limits(), '-ping', $path.'[0]', '-format', '%w %h', 'info:'], 60);

        if (! preg_match('/^(\d+) (\d+)/', trim($process->getOutput()), $m)) {
            throw new RuntimeException('Could not determine image dimensions.');
        }

        return [(int) $m[1], (int) $m[2]];
    }

    private function rasteriseSvg(ProcessingContext $context): string
    {
        $sanitizer = new Sanitizer;
        $sanitizer->removeRemoteReferences(true);
        $clean = $sanitizer->sanitize(file_get_contents($context->sourcePath));

        if ($clean === false || trim($clean) === '') {
            throw new RuntimeException('SVG could not be sanitised safely.');
        }

        $svg = $context->path('sanitised.svg');
        file_put_contents($svg, $clean);
        $png = $context->path('rasterised.png');

        $this->runner->mustRun('magick', [...$this->limits(), '-density', '150', '-background', 'white', 'svg:'.$svg, '-flatten', 'png:'.$png], $context->timeout());

        return $png;
    }

    /**
     * ImageMagick resource limits applied to every invocation.
     *
     * @return list<string>
     */
    private function limits(): array
    {
        $memory = config('dam.processing.image.memory_limit');
        $pixels = config('dam.processing.image.max_megapixels');

        return ['-limit', 'memory', $memory, '-limit', 'map', $memory, '-limit', 'area', $pixels.'MP', '-limit', 'disk', '4GiB', '-limit', 'thread', '2'];
    }
}

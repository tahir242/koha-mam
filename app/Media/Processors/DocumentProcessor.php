<?php

namespace App\Media\Processors;

use App\Enums\FileRole;
use App\Media\ProcessingContext;
use App\Media\ToolRunner;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Documents are normalised to a PDF viewing derivative (LibreOffice
 * headless for Office/text formats, calibre for EPUB when configured),
 * then thumbnailed — and optionally rendered to page images — with Poppler.
 */
class DocumentProcessor implements MediaProcessor
{
    public function __construct(private readonly ToolRunner $runner) {}

    public function process(ProcessingContext $context): void
    {
        $pdf = $this->toPdf($context);
        $context->progress('derivatives', 40);

        $pages = $this->pageCount($pdf, $context);
        $context->mergeMetadata(['pages' => $pages]);

        if ($pages > config('dam.processing.pdf.max_pages')) {
            throw new RuntimeException("Document has {$pages} pages; the limit is ".config('dam.processing.pdf.max_pages').'.');
        }

        // The viewer PDF is a separate derivative object; the original is never served.
        $context->store($pdf, FileRole::ViewerPdf, 'application/pdf', 'document.pdf', ['metadata' => ['pages' => $pages]]);
        $context->progress('derivatives', 55);

        $thumbBase = $context->path('out/thumbnail');
        $this->runner->mustRun('pdftoppm', ['-f', '1', '-l', '1', '-singlefile', '-png', '-scale-to', (string) config('dam.processing.image.thumbnail'), $pdf, $thumbBase], 120);
        [$w, $h] = getimagesize($thumbBase.'.png') ?: [null, null];
        $context->store($thumbBase.'.png', FileRole::Thumbnail, 'image/png', 'thumbnail.png', ['width' => $w, 'height' => $h]);
        $context->progress('derivatives', 65);

        if (config('dam.processing.pdf.page_images')) {
            $this->renderPages($context, $pdf, $pages);
        }
    }

    public function requiredRoles(ProcessingContext $context): array
    {
        return config('dam.processing.pdf.page_images')
            ? [FileRole::ViewerPdf, FileRole::Thumbnail, FileRole::PageImage]
            : [FileRole::ViewerPdf, FileRole::Thumbnail];
    }

    public function requiredTools(): array
    {
        return ['pdftoppm', 'pdfinfo', 'soffice'];
    }

    private function toPdf(ProcessingContext $context): string
    {
        $version = $context->version;

        // PDFs needing a user password fail in pageCount() via pdfinfo.
        if ($version->detected_mime === 'application/pdf') {
            return $context->sourcePath;
        }

        if ($version->extension === 'epub') {
            if (! $this->runner->binary('ebook_convert')) {
                throw new RuntimeException('EPUB viewing requires calibre\'s ebook-convert (DAM_EBOOK_CONVERT_BINARY). The original has been stored for administrative review.');
            }

            $out = $context->path('out/converted.pdf');
            $this->runner->mustRun('ebook_convert', [$context->sourcePath, $out], $context->timeout());

            return $out;
        }

        // LibreOffice: isolated profile per job so parallel conversions do not collide.
        $profile = $context->path('lo-profile');
        $outDir = $context->path('lo-out');
        File::ensureDirectoryExists($outDir);
        $profileUrl = 'file:///'.ltrim(str_replace('\\', '/', $profile), '/');

        $this->runner->mustRun('soffice', [
            '-env:UserInstallation='.$profileUrl,
            '--headless', '--norestore', '--nolockcheck', '--nodefault', '--nologo',
            '--convert-to', 'pdf',
            '--outdir', $outDir,
            $context->sourcePath,
        ], $context->timeout(), $context->workDir, null, ['HOME' => $profile]);

        $pdf = $outDir.DIRECTORY_SEPARATOR.pathinfo($context->sourcePath, PATHINFO_FILENAME).'.pdf';

        if (! is_file($pdf) || filesize($pdf) === 0) {
            throw new RuntimeException('LibreOffice did not produce a PDF.');
        }

        return $pdf;
    }

    private function pageCount(string $pdf, ProcessingContext $context): int
    {
        $process = $this->runner->run('pdfinfo', [$pdf], 60);

        if (! $process->isSuccessful()) {
            throw new RuntimeException(str_contains(strtolower($process->getErrorOutput()), 'password')
                ? 'The PDF is password-protected and cannot be processed.'
                : 'The PDF could not be read; the file may be damaged.');
        }

        $output = $process->getOutput();

        if (! preg_match('/^Pages:\s+(\d+)/m', $output, $m) || (int) $m[1] < 1) {
            throw new RuntimeException('Could not read the PDF page count; the file may be damaged.');
        }

        return (int) $m[1];
    }

    private function renderPages(ProcessingContext $context, string $pdf, int $pages): void
    {
        $dir = $context->path('pages');
        File::ensureDirectoryExists($dir);
        $dpi = (string) config('dam.processing.pdf.page_image_dpi');

        $this->runner->mustRun('pdftoppm', ['-r', $dpi, '-png', $pdf, $dir.DIRECTORY_SEPARATOR.'page'], $context->timeout());

        $files = collect(File::files($dir))->sortBy(fn ($f) => $f->getFilename())->values();

        foreach ($files as $index => $file) {
            [$w, $h] = getimagesize($file->getPathname()) ?: [null, null];
            $page = $index + 1;
            $context->store($file->getPathname(), FileRole::PageImage, 'image/png', sprintf('pages/page-%05d.png', $page), ['page' => $page, 'width' => $w, 'height' => $h]);
            $context->progress('pages', 65 + (int) ($page / max(1, $pages) * 30));
        }
    }
}

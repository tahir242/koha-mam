<?php

namespace App\Health;

use App\Media\ToolRunner;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

class MediaToolsCheck extends Check
{
    public function run(): Result
    {
        $runner = app(ToolRunner::class);
        $required = ['ffmpeg', 'ffprobe', 'pdftoppm', 'pdfinfo', 'magick', 'soffice'];

        if (config('dam.scanning.driver') === 'clamav') {
            $required[] = 'clamdscan';
        }

        $missing = array_values(array_filter($required, fn ($tool) => ! $runner->isAvailable($tool)));
        $result = Result::make()->meta(['missing' => $missing]);

        if (config('dam.scanning.required') && config('dam.scanning.driver') !== 'clamav') {
            return $result->failed('Malware scanning is required but no scanner is configured.')->shortSummary('No scanner');
        }

        return $missing === []
            ? $result->ok()->shortSummary('All available')
            : $result->failed('Missing: '.implode(', ', $missing))->shortSummary(count($missing).' missing');
    }
}

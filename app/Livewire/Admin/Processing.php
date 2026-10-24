<?php

namespace App\Livewire\Admin;

use App\Media\MediaTypeRegistry;
use App\Media\ToolRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Processing')]
class Processing extends Component
{
    public function boot(): void
    {
        $this->authorize('media.manage_settings');
    }

    public function render(ToolRunner $runner, MediaTypeRegistry $registry)
    {
        $tools = collect(config('dam.processing.binaries'))->map(fn ($binary, $tool) => [
            'tool' => $tool,
            'binary' => $binary,
            'available' => $runner->isAvailable($tool),
        ]);

        $failedJobs = DB::table('failed_jobs')->latest('failed_at')->limit(10)->get(['id', 'queue', 'failed_at', 'exception'])
            ->map(fn ($job) => (object) [
                'id' => $job->id,
                'queue' => $job->queue,
                'failed_at' => $job->failed_at,
                'error' => Str::limit(strtok($job->exception, "\n"), 200),
            ]);

        return view('livewire.admin.processing', [
            'tools' => $tools,
            'formats' => $registry->extensionsByType(),
            'failedJobs' => $failedJobs,
            'config' => [
                'Queue connection' => config('queue.default'),
                'Media queue connection' => config('dam.processing.queue_connection') ?: config('queue.default'),
                'Process priority (nice)' => config('dam.processing.nice'),
                'FFmpeg threads' => config('dam.processing.threads'),
                'Video renditions' => collect(config('dam.processing.video.renditions'))->pluck('height')->map(fn ($h) => $h.'p')->implode(', '),
                'HLS segment length' => config('dam.processing.video.hls_segment_seconds').' s',
                'Image size limit' => config('dam.processing.image.max_megapixels').' MP',
                'PDF page images' => config('dam.processing.pdf.page_images') ? 'Enabled' : 'Disabled (PDF.js)',
                'Malware scanning' => config('dam.scanning.driver'),
                'Unsupported formats' => config('dam.upload.allow_unsupported') ? 'Stored for review' : 'Rejected',
            ],
        ]);
    }
}

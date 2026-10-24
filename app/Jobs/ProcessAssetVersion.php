<?php

namespace App\Jobs;

use App\Assets\VersionPipeline;
use App\Models\AssetVersion;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Generate viewing derivatives. Dispatched onto a per-media-type queue
 * (media-documents, media-images, media-audio, media-video) so Horizon can
 * cap concurrency for expensive work independently.
 */
class ProcessAssetVersion implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Conversions are deterministic; failures are retried explicitly by staff. */
    public int $tries = 1;

    /** Upper bound; the per-tool timeout from config applies inside. */
    public int $timeout = 14700;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $versionId)
    {
        if ($connection = config('dam.processing.queue_connection')) {
            $this->onConnection($connection);
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->versionId;
    }

    public function uniqueFor(): int
    {
        return $this->timeout + 300;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('version:'.$this->versionId))->expireAfter($this->timeout + 60)->releaseAfter(60)];
    }

    public function handle(VersionPipeline $pipeline): void
    {
        $version = AssetVersion::find($this->versionId);

        if ($version) {
            $pipeline->process($version);
        }
    }

    public function failed(Throwable $exception): void
    {
        if ($version = AssetVersion::find($this->versionId)) {
            app(VersionPipeline::class)->fail($version, $exception);
        }
    }
}

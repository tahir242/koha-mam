<?php

namespace App\Jobs;

use App\Assets\VersionPipeline;
use App\Media\Scanning\ScanFailedException;
use App\Models\AssetVersion;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use League\Flysystem\FilesystemException;
use Throwable;

/** Validate, malware-scan and store the original of an uploaded version. */
class IngestAssetVersion implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public array $backoff = [30, 120];

    public function __construct(public readonly int $versionId)
    {
        $this->onQueue('ingest');
    }

    public function uniqueId(): string
    {
        return (string) $this->versionId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('version:'.$this->versionId))->expireAfter($this->timeout + 60)->releaseAfter(30)];
    }

    public function handle(VersionPipeline $pipeline): void
    {
        $version = AssetVersion::find($this->versionId);

        if (! $version) {
            return;
        }

        try {
            $pipeline->ingest($version);
        } catch (ScanFailedException|FilesystemException $e) {
            // Scanner or storage outage: let the queue retry with backoff.
            throw $e;
        } catch (Throwable $e) {
            // Validation failures and detected malware are final.
            $pipeline->fail($version, $e);
        }
    }

    public function failed(Throwable $exception): void
    {
        if ($version = AssetVersion::find($this->versionId)) {
            app(VersionPipeline::class)->fail($version, $exception);
        }
    }
}

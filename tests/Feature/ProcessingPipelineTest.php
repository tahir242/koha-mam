<?php

namespace Tests\Feature;

use App\Assets\VersionPipeline;
use App\Enums\FileRole;
use App\Enums\VersionStatus;
use App\Jobs\IngestAssetVersion;
use App\Jobs\ProcessAssetVersion;
use App\Media\Scanning\MalwareScanner;
use App\Media\Scanning\ScanResult;
use App\Media\ToolRunner;
use App\Models\AssetVersion;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Publishing\AssetWorkflow;
use App\States\Asset\ProcessingFailed;
use App\States\Asset\ReadyForReview;
use App\States\Asset\Uploaded;
use App\Storage\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessingPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function quarantinedVersion(string $content, string $ext, string $mime, string $mediaType): AssetVersion
    {
        $asset = DigitalAsset::factory()->inState(Uploaded::class)->create(['media_type' => $mediaType])->fresh();
        $path = 'incoming/'.uniqid().'.bin';
        Storage::disk('dam_quarantine')->put($path, $content);

        return $asset->versions()->create([
            'version_number' => 1,
            'status' => VersionStatus::Received,
            'original_filename' => "file.{$ext}",
            'detected_mime' => $mime,
            'extension' => $ext,
            'size_bytes' => strlen($content),
            'checksum_sha256' => hash('sha256', $content),
            'quarantine_path' => $path,
        ]);
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(320, 200);
        imagefill($image, 0, 0, imagecolorallocate($image, 6, 59, 92));
        ob_start();
        imagepng($image);

        return ob_get_clean();
    }

    public function test_ingest_scans_and_stores_original_then_queues_processing(): void
    {
        Queue::fake();
        Storage::fake('dam_quarantine');
        $backend = $this->localBackend();
        $version = $this->quarantinedVersion($this->png(), 'png', 'image/png', 'image');

        app(VersionPipeline::class)->ingest($version);

        $version->refresh();
        $original = $version->file(FileRole::Original);
        $this->assertNotNull($original);
        $this->assertSame($backend->id, $original->storage_backend_id);
        $this->assertNull($version->quarantine_path);
        $this->assertFileExists(app(StorageManager::class)->absolutePath($backend, $original->path));
        Queue::assertPushedOn('media-images', ProcessAssetVersion::class);
    }

    public function test_malware_is_destroyed_and_never_stored(): void
    {
        Queue::fake();
        Storage::fake('dam_quarantine');
        $this->localBackend();
        $this->app->instance(MalwareScanner::class, new class implements MalwareScanner
        {
            public function scan(string $absolutePath): ScanResult
            {
                return ScanResult::infected('test', 'Eicar-Test-Signature');
            }

            public function name(): string
            {
                return 'test';
            }
        });

        $version = $this->quarantinedVersion($this->png(), 'png', 'image/png', 'image');
        (new IngestAssetVersion($version->id))->handle(app(VersionPipeline::class));

        $version->refresh();
        $this->assertSame(VersionStatus::Failed, $version->status);
        $this->assertNull($version->file(FileRole::Original));
        $this->assertNull($version->quarantine_path);
        $this->assertTrue($version->asset->state->equals(ProcessingFailed::class));
        $this->assertDatabaseHas('activity_log', ['event' => 'malware_detected']);
    }

    public function test_tampered_quarantine_file_fails_checksum(): void
    {
        Queue::fake();
        Storage::fake('dam_quarantine');
        $this->localBackend();
        $version = $this->quarantinedVersion($this->png(), 'png', 'image/png', 'image');
        Storage::disk('dam_quarantine')->put($version->quarantine_path, 'swapped');

        (new IngestAssetVersion($version->id))->handle(app(VersionPipeline::class));

        $this->assertSame(VersionStatus::Failed, $version->fresh()->status);
        $this->assertStringContainsString('Checksum', $version->fresh()->processing_error);
    }

    public function test_full_image_pipeline_produces_verified_derivatives(): void
    {
        if (! app(ToolRunner::class)->isAvailable('magick')) {
            $this->markTestSkipped('ImageMagick is not installed.');
        }

        Storage::fake('dam_quarantine');
        config(['dam.storage.work_path' => $this->tempDir()]);
        $this->localBackend();
        $version = $this->quarantinedVersion($this->png(), 'png', 'image/png', 'image');

        // QUEUE_CONNECTION=sync runs the processing job inline.
        (new IngestAssetVersion($version->id))->handle(app(VersionPipeline::class));

        $version->refresh();
        $this->assertSame(VersionStatus::Ready, $version->status, (string) $version->processing_error);
        $this->assertTrue($version->is_viewable);
        foreach ([FileRole::ImageLarge, FileRole::ImageMedium, FileRole::Thumbnail] as $role) {
            $this->assertNotNull($version->file($role), "{$role->value} missing");
        }
        $this->assertSame(320, $version->metadata['width']);
        $this->assertTrue($version->asset->fresh()->state->equals(ReadyForReview::class));
        $this->assertSame($version->id, $version->asset->fresh()->current_version_id);
    }

    public function test_failed_conversion_marks_failure_and_can_be_retried(): void
    {
        if (! app(ToolRunner::class)->isAvailable('magick')) {
            $this->markTestSkipped('ImageMagick is not installed.');
        }

        Storage::fake('dam_quarantine');
        config(['dam.storage.work_path' => $this->tempDir()]);
        $this->localBackend();
        // A truncated PNG passes type detection but cannot be decoded.
        $broken = substr($this->png(), 0, 60);
        $version = $this->quarantinedVersion($broken, 'png', 'image/png', 'image');

        (new IngestAssetVersion($version->id))->handle(app(VersionPipeline::class));
        try {
            (new ProcessAssetVersion($version->id))->handle(app(VersionPipeline::class));
        } catch (\Throwable $e) {
            (new ProcessAssetVersion($version->id))->failed($e);
        }

        $version->refresh();
        $this->assertSame(VersionStatus::Failed, $version->status);
        $this->assertTrue($version->asset->state->equals(ProcessingFailed::class));

        Queue::fake();
        app(AssetWorkflow::class)->retryProcessing($version->asset->fresh(), User::factory()->create());
        Queue::assertPushed(ProcessAssetVersion::class);
    }

    public function test_decompression_bomb_dimensions_are_rejected(): void
    {
        if (! app(ToolRunner::class)->isAvailable('magick')) {
            $this->markTestSkipped('ImageMagick is not installed.');
        }

        Storage::fake('dam_quarantine');
        config(['dam.storage.work_path' => $this->tempDir(), 'dam.processing.image.max_megapixels' => 0]);
        $this->localBackend();
        $version = $this->quarantinedVersion($this->png(), 'png', 'image/png', 'image');

        (new IngestAssetVersion($version->id))->handle(app(VersionPipeline::class));

        $this->assertSame(VersionStatus::Failed, $version->fresh()->status);
        $this->assertStringContainsString('megapixels', $version->fresh()->processing_error);
    }

    public function test_tool_runner_never_uses_a_shell(): void
    {
        config(['dam.processing.binaries.php' => PHP_BINARY]);
        $process = (new ToolRunner)->run('php', ['-r', 'echo $argv[1];', 'a; rm -rf / $(whoami) `id`'], 30);

        $this->assertSame('a; rm -rf / $(whoami) `id`', $process->getOutput());
    }
}

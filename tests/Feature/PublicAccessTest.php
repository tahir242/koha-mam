<?php

namespace Tests\Feature;

use App\Delivery\MediaAccess;
use App\Enums\FileRole;
use App\Models\DigitalAsset;
use App\States\Asset\Draft;
use App\States\Asset\PendingApproval;
use App\States\Asset\Published;
use App\States\Asset\ReadyForReview;
use App\States\Asset\Unpublished;
use App\Storage\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PublicAccessTest extends TestCase
{
    use RefreshDatabase;

    private function asset(string $state = Published::class, array $attributes = [], array $roles = [FileRole::ViewerPdf, FileRole::Thumbnail]): DigitalAsset
    {
        return DigitalAsset::factory()->processed($this->localBackend(), $roles)->inState($state)->create($attributes)->fresh(['currentVersion.files']);
    }

    private function signed(DigitalAsset $asset, FileRole $role): string
    {
        $file = $asset->currentVersion->file($role);

        return URL::temporarySignedRoute('media.file', now()->addMinutes(5), ['asset' => $asset->uuid, 'file' => $file->id]);
    }

    public function test_published_public_asset_is_viewable_without_login(): void
    {
        $asset = $this->asset();

        $this->get('/view/'.$asset->uuid)
            ->assertOk()
            ->assertSee($asset->title)
            ->assertDontSee('download', false)
            ->assertHeader('X-Frame-Options', 'DENY');

        $this->assertDatabaseHas('media_access_events', ['digital_asset_id' => $asset->id, 'event' => 'open', 'is_staff' => false]);
    }

    public function test_viewer_csp_is_strict(): void
    {
        $csp = $this->get('/view/'.$this->asset()->uuid)->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString("'unsafe-eval'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    public function test_draft_pending_and_unpublished_assets_are_not_found(): void
    {
        foreach ([Draft::class, ReadyForReview::class, PendingApproval::class, Unpublished::class] as $state) {
            $asset = $this->asset($state);
            $this->get('/view/'.$asset->uuid)->assertNotFound();
            $this->get($this->signed($asset, FileRole::ViewerPdf))->assertNotFound();
        }
    }

    public function test_restricted_assets_are_hidden_from_the_public_but_visible_to_staff(): void
    {
        $asset = $this->asset(attributes: ['access_level' => 'restricted']);

        $this->get('/view/'.$asset->uuid)->assertNotFound();
        $this->get($this->signed($asset, FileRole::ViewerPdf))->assertNotFound();

        $this->actingAsStaff();
        $this->get('/view/'.$asset->uuid)->assertOk();
        $this->get($this->signed($asset, FileRole::ViewerPdf))->assertOk();
    }

    public function test_staff_can_preview_unpublished_assets(): void
    {
        $asset = $this->asset(ReadyForReview::class);
        $this->actingAsStaff();

        $this->get('/view/'.$asset->uuid)->assertOk()->assertSee('Staff preview');
    }

    public function test_original_files_are_never_served_even_with_a_valid_signature(): void
    {
        $asset = $this->asset();
        $this->get($this->signed($asset, FileRole::Original))->assertNotFound();

        $this->actingAsStaff('administrator');
        $this->get($this->signed($asset, FileRole::Original))->assertNotFound();
    }

    public function test_derivatives_require_a_valid_unexpired_signature(): void
    {
        $asset = $this->asset();
        $file = $asset->currentVersion->file(FileRole::ViewerPdf);

        $this->get("/media/{$asset->uuid}/{$file->id}")->assertForbidden();
        $this->get($this->signed($asset, FileRole::ViewerPdf).'x')->assertForbidden();

        $expired = URL::temporarySignedRoute('media.file', now()->subMinute(), ['asset' => $asset->uuid, 'file' => $file->id]);
        $this->get($expired)->assertForbidden();
    }

    public function test_media_response_headers_prevent_download_and_sniffing(): void
    {
        $response = $this->get($this->signed($this->asset(), FileRole::ViewerPdf))->assertOk();

        $response->assertHeader('Content-Disposition', 'inline');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
    }

    public function test_file_of_another_asset_cannot_be_reached_through_a_public_asset(): void
    {
        $public = $this->asset();
        $private = $this->asset(Draft::class);
        $file = $private->currentVersion->file(FileRole::ViewerPdf);

        $url = URL::temporarySignedRoute('media.file', now()->addMinutes(5), ['asset' => $public->uuid, 'file' => $file->id]);
        $this->get($url)->assertNotFound();
    }

    public function test_hls_path_traversal_is_rejected(): void
    {
        $asset = $this->asset(roles: [FileRole::HlsMaster]);
        $master = $asset->currentVersion->file(FileRole::HlsMaster);

        foreach (['../original/x.pdf', '360p/../../original/x', 'seg.php', '360p/seg_00001.php'] as $path) {
            $url = URL::temporarySignedRoute('media.hls', now()->addMinutes(5), ['asset' => $asset->uuid, 'file' => $master->id, 'path' => $path]);
            $this->assertContains($this->get($url)->status(), [404], "Path {$path} must be rejected");
        }
    }

    public function test_hls_playlist_uris_are_rewritten_to_signed_urls(): void
    {
        $asset = $this->asset(roles: [FileRole::HlsMaster]);
        $master = $asset->currentVersion->file(FileRole::HlsMaster);
        $disk = app(StorageManager::class)->disk($master->storageBackend);
        $disk->put($master->directory().'/master.m3u8', "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\n360p/index.m3u8\n");

        $body = $this->get(app(MediaAccess::class)->signedHlsUrl($asset, $master, 'master.m3u8'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#/media/'.$asset->uuid.'/'.$master->id.'/hls/360p/index\.m3u8\?expires=\d+&signature=#', $body);
    }

    public function test_cross_site_media_requests_are_blocked(): void
    {
        $this->withHeader('Sec-Fetch-Site', 'cross-site')->get($this->signed($this->asset(), FileRole::ViewerPdf))->assertForbidden();
    }

    public function test_no_public_download_or_storage_routes_exist(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())->map->uri();

        $this->assertEmpty($uris->filter(fn ($uri) => str_contains($uri, 'download')));
        $this->assertEmpty($uris->filter(fn ($uri) => str_starts_with($uri, 'storage/')));
        $this->get('/storage/dam/anything')->assertNotFound();
    }

    public function test_missing_storage_file_results_in_not_found(): void
    {
        $asset = $this->asset();
        $file = $asset->currentVersion->file(FileRole::ViewerPdf);
        app(StorageManager::class)->disk($file->storageBackend)->delete($file->path);

        $this->get($this->signed($asset, FileRole::ViewerPdf))->assertNotFound();
    }

    public function test_unknown_or_malformed_viewer_ids_are_not_found(): void
    {
        $this->get('/view/not-a-uuid')->assertNotFound();
        $this->get('/view/01a1674d-0000-7000-8000-000000000000')->assertNotFound();
    }
}

<?php

namespace Tests\Feature;

use App\Enums\FileRole;
use App\Models\DigitalAsset;
use App\Models\MediaAccessEvent;
use App\States\Asset\Published;
use App\States\Asset\ReadyForReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ViewCountingTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36';

    private function asset(string $state = Published::class, array $attributes = []): DigitalAsset
    {
        return DigitalAsset::factory()->processed($this->localBackend(), [FileRole::ViewerPdf, FileRole::Thumbnail])
            ->inState($state)->create($attributes)->fresh(['currentVersion.files']);
    }

    /** Open the viewer page and return the view token it hands out. */
    private function open(DigitalAsset $asset, string $agent = self::BROWSER): string
    {
        $html = $this->withHeader('User-Agent', $agent)->get('/view/'.$asset->uuid)->assertOk()->getContent();
        preg_match('/data-view-token="([^"]+)"/', $html, $m);

        $this->assertNotEmpty($m[1] ?? null, 'The viewer page carries a view token.');

        return html_entity_decode($m[1]);
    }

    private function engage(DigitalAsset $asset, string $token, string $agent = self::BROWSER): TestResponse
    {
        return $this->withHeader('User-Agent', $agent)->postJson('/view/'.$asset->uuid.'/engagement', ['token' => $token]);
    }

    /** Open, wait the qualifying time, report: one full viewing. */
    private function watch(DigitalAsset $asset, string $agent = self::BROWSER): TestResponse
    {
        $token = $this->open($asset, $agent);
        $this->travel(11)->seconds();

        return $this->engage($asset, $token, $agent);
    }

    public function test_opening_the_page_is_not_a_view(): void
    {
        $asset = $this->asset();

        $this->open($asset);

        $this->assertSame(0, $asset->fresh()->view_count);
        $this->assertDatabaseHas('media_access_events', ['digital_asset_id' => $asset->id, 'event' => 'open']);
        $this->assertDatabaseMissing('media_access_events', ['digital_asset_id' => $asset->id, 'event' => 'view']);
    }

    public function test_engaged_view_is_counted_and_shown(): void
    {
        $asset = $this->asset();

        $this->watch($asset)->assertOk()->assertJson(['counted' => true, 'views' => 1])->assertHeader('Cache-Control', 'no-store, private');

        $asset->refresh();
        $this->assertSame(1, $asset->view_count);
        $this->assertNotNull($asset->last_viewed_at);
        $this->assertDatabaseHas('media_access_events', ['digital_asset_id' => $asset->id, 'event' => 'view', 'counted' => true]);
        $this->get('/view/'.$asset->uuid)->assertSee('1 view');
    }

    public function test_report_before_the_qualifying_time_is_refused(): void
    {
        $asset = $this->asset();
        $token = $this->open($asset);

        $this->travel(3)->seconds();
        $this->engage($asset, $token)->assertJson(['counted' => false]);

        $this->assertSame(0, $asset->fresh()->view_count);
    }

    public function test_a_token_counts_once_and_only_for_its_asset(): void
    {
        $asset = $this->asset();
        $other = $this->asset();
        $token = $this->open($asset);
        $this->travel(11)->seconds();

        $this->engage($other, $token)->assertJson(['counted' => false]);
        $this->engage($asset, $token)->assertJson(['counted' => true]);
        $this->travel(31)->minutes();
        $this->engage($asset, $token)->assertJson(['counted' => false]);

        $this->assertSame(1, $asset->fresh()->view_count);
        $this->assertSame(0, $other->fresh()->view_count);
    }

    public function test_forged_or_expired_tokens_are_refused(): void
    {
        $asset = $this->asset();

        $this->engage($asset, 'not-a-token')->assertJson(['counted' => false]);
        $this->postJson('/view/'.$asset->uuid.'/engagement', [])->assertStatus(422);

        $token = $this->open($asset);
        $this->travel(7)->hours();
        $this->engage($asset, $token)->assertJson(['counted' => false]);

        $this->assertSame(0, $asset->fresh()->view_count);
    }

    public function test_repeat_views_count_again_only_after_a_pause_up_to_a_daily_cap(): void
    {
        config(['dam.viewer.views.max_per_viewer_per_day' => 2]);
        $this->travelTo(now()->startOfDay()->addHours(8));
        $asset = $this->asset();

        $this->watch($asset)->assertJson(['counted' => true]);
        $this->travel(5)->minutes();
        $this->watch($asset)->assertJson(['counted' => false]);
        $this->travel(31)->minutes();
        $this->watch($asset)->assertJson(['counted' => true]);
        $this->travel(31)->minutes();
        $this->watch($asset)->assertJson(['counted' => false]);

        $this->assertSame(2, $asset->fresh()->view_count);
        $this->assertDatabaseHas('media_access_events', ['digital_asset_id' => $asset->id, 'event' => 'view', 'counted' => false, 'reason' => 'repeat']);
        $this->assertDatabaseHas('media_access_events', ['digital_asset_id' => $asset->id, 'event' => 'view', 'counted' => false, 'reason' => 'daily_cap']);

        // A different viewer counts independently.
        $this->watch($asset, self::BROWSER.' Firefox/140.0')->assertJson(['counted' => true]);
        $this->assertSame(3, $asset->fresh()->view_count);
    }

    public function test_bots_are_not_counted(): void
    {
        $asset = $this->asset();

        foreach (['Googlebot/2.1 (+http://www.google.com/bot.html)', 'curl/8.4.0', 'python-requests/2.32'] as $agent) {
            $this->watch($asset, $agent)->assertJson(['counted' => false]);
        }

        $this->assertSame(0, $asset->fresh()->view_count);
        $this->assertSame(3, MediaAccessEvent::where('reason', 'bot')->count());
    }

    public function test_staff_views_are_recorded_but_not_counted(): void
    {
        $this->actingAsStaff();
        $asset = $this->asset();

        $this->watch($asset)->assertJson(['counted' => false]);

        $this->assertSame(0, $asset->fresh()->view_count);
        $this->assertDatabaseHas('media_access_events', ['digital_asset_id' => $asset->id, 'event' => 'view', 'is_staff' => true, 'reason' => 'staff']);
    }

    public function test_unpublished_previews_are_never_counted(): void
    {
        $this->actingAsStaff();
        config(['dam.viewer.views.count_staff' => true]);
        $asset = $this->asset(ReadyForReview::class);

        $this->watch($asset)->assertJson(['counted' => false]);
        $this->assertSame(0, $asset->fresh()->view_count);
    }

    public function test_short_audio_and_video_qualify_at_half_their_length(): void
    {
        $asset = $this->asset(Published::class, ['media_type' => 'audio']);
        $asset->currentVersion->forceFill(['metadata' => ['duration' => 12.4]])->save();

        $html = $this->get('/view/'.$asset->uuid)->getContent();

        $this->assertStringContainsString('data-view-seconds="7"', $html);
    }

    public function test_views_can_be_switched_off(): void
    {
        config(['dam.viewer.record_views' => false]);
        $asset = $this->asset();

        $this->get('/view/'.$asset->uuid)->assertOk()->assertDontSee('data-view-token', false);
        $this->assertSame(0, MediaAccessEvent::count());
    }

    public function test_staff_see_view_analytics_and_can_sort_by_views(): void
    {
        $popular = $this->asset(Published::class, ['title' => 'Popular lecture']);
        $quiet = $this->asset(Published::class, ['title' => 'Quiet scan']);
        $this->watch($popular);
        $this->watch($popular, self::BROWSER.' Firefox/140.0');
        $this->watch($quiet);

        $this->actingAsStaff('administrator');

        $this->get('/staff/assets/'.$popular->uuid)->assertOk()->assertSee('Views')->assertSee('Views, last 30 days');
        $this->get('/staff/library?sort=views&view=list')->assertOk()->assertSeeInOrder(['Popular lecture', 'Quiet scan']);
        $this->get('/staff')->assertOk()->assertSee('Most viewed');
    }
}

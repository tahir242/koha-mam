<?php

namespace Tests\Feature;

use App\Livewire\AssetDetail;
use App\Models\DigitalAsset;
use App\States\Asset\PendingApproval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\FakeKoha;
use Tests\TestCase;

class StaffAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_the_koha_sign_in_page(): void
    {
        $this->get('/staff')->assertRedirect(route('login'));
        $this->get('/staff/library')->assertRedirect(route('login'));
    }

    public function test_cataloguer_cannot_open_administration_or_approvals(): void
    {
        $this->actingAsStaff();

        foreach (['/staff/approvals', '/staff/admin/integration', '/staff/admin/storage', '/staff/admin/policies', '/staff/admin/settings', '/staff/admin/staff', '/staff/admin/audit', '/staff/admin/processing'] as $uri) {
            $this->get($uri)->assertForbidden();
        }
    }

    public function test_administrator_can_open_every_page(): void
    {
        $this->localBackend();
        $this->actingAsStaff('administrator');
        Http::fake(['*' => Http::response(['access_token' => 'x', 'expires_in' => 60])]);

        foreach (['/staff', '/staff/library', '/staff/approvals', '/staff/publications', '/staff/admin/integration', '/staff/admin/storage', '/staff/admin/policies', '/staff/admin/settings', '/staff/admin/staff', '/staff/admin/audit', '/staff/admin/processing'] as $uri) {
            $this->get($uri)->assertOk();
        }
    }

    public function test_workspace_loads_koha_metadata(): void
    {
        FakeKoha::install()->withRecord(42);
        $this->actingAsStaff();

        $this->get('/staff/biblios/42')
            ->assertOk()
            ->assertSee('Renal transplantation')
            ->assertSee('Rizvi, Adib')
            ->assertSee('2nd ed.')
            ->assertSee('9780199608249');
    }

    public function test_item_workspace_shows_the_item_and_uploads_to_it(): void
    {
        FakeKoha::install()->withRecord(42)->withItem(456, 42, '39999000123');
        $this->actingAsStaff();
        DigitalAsset::factory()->create(['biblio_id' => 42, 'item_id' => 456, 'title' => 'Item scan', 'item_snapshot' => ['item_id' => 456, 'biblio_id' => 42, 'barcode' => '39999000123']]);
        DigitalAsset::factory()->create(['biblio_id' => 42, 'title' => 'Record scan']);

        $this->get('/staff/biblios/42?item=456')
            ->assertOk()
            ->assertSee('Item #456')
            ->assertSee('Barcode 39999000123')
            ->assertSeeInOrder(['Media of this item', 'Item scan', 'Other media of this record', 'Record scan'])
            ->assertSee('Upload media for Item #456')
            ->assertSee('\u0022itemId\u0022:456', false);
    }

    public function test_item_workspace_without_barcode_or_for_a_foreign_item(): void
    {
        FakeKoha::install()->withRecord(42)->withItem(456, 42)->withItem(789, 99, 'OTHER');
        $this->actingAsStaff();

        $this->get('/staff/biblios/42?item=456')->assertOk()->assertSee('Item #456')->assertSee('No barcode yet');

        $this->get('/staff/biblios/42?item=789')->assertOk()
            ->assertSee('no longer exists in Koha or now belongs to another record')
            ->assertDontSee('OTHER')
            ->assertSee('\u0022itemId\u0022:null', false);

        $this->get('/staff/biblios/42?item=abc')->assertOk()->assertDontSee('Upload media for Item');
    }

    public function test_workspace_handles_deleted_records(): void
    {
        $this->actingAsStaff();
        FakeKoha::install();

        $this->get('/staff/biblios/404')->assertOk()->assertSee('Record not found in Koha');
    }

    public function test_workspace_degrades_gracefully_when_koha_is_unreachable(): void
    {
        $this->actingAsStaff();
        Http::fake(['*' => Http::response('down', 503)]);

        $this->get('/staff/biblios/7')->assertOk()->assertSee('Koha is currently unreachable');
    }

    public function test_cataloguer_cannot_approve_through_livewire(): void
    {
        $asset = DigitalAsset::factory()->processed($this->localBackend())->inState(PendingApproval::class)->create()->fresh();
        $this->actingAsStaff();

        Livewire::test(AssetDetail::class, ['asset' => $asset])->call('approve');

        $this->assertTrue($asset->fresh()->state->equals(PendingApproval::class));
    }

    public function test_security_headers_are_present_on_staff_pages(): void
    {
        $this->actingAsStaff();
        $response = $this->get('/staff/library');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
        // COOP only on HTTPS: browsers ignore it on plain HTTP and log a warning.
        $response->assertHeaderMissing('Cross-Origin-Opener-Policy');
        $this->get('https://localhost/staff/library')->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
    }

    public function test_missing_cover_is_an_empty_success_for_staff_and_hidden_from_the_public(): void
    {
        config(['dam.koha.opac_url' => 'https://opac.test']);
        // Koha answers a 1x1 GIF when the record has no local cover.
        Http::fake(['opac.test/*' => Http::response('GIF89a', 200, ['Content-Type' => 'image/gif'])]);

        $this->get('/covers/42')->assertNotFound();

        $this->actingAsStaff();
        $this->get('/covers/42')->assertNoContent();
    }

    public function test_health_endpoint_requires_token(): void
    {
        $this->get('/health.json')->assertNotFound();

        config(['dam.health.token' => 'secret-token']);
        $this->get('/health.json', ['Authorization' => 'Bearer wrong'])->assertNotFound();
        $this->get('/health.json', ['Authorization' => 'Bearer secret-token'])->assertOk();
    }
}

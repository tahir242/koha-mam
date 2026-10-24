<?php

namespace Tests\Feature;

use App\Assets\UploadIntake;
use App\Assets\UploadRejectedException;
use App\Enums\VersionStatus;
use App\Jobs\IngestAssetVersion;
use App\Models\AssetVersion;
use App\Models\DigitalAsset;
use App\Models\User;
use App\States\Asset\Published;
use App\States\Asset\Uploaded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeKoha;
use Tests\TestCase;

class UploadTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('dam_quarantine');
    }

    private function upload(string $name, string $content, array $extra = [], ?string $range = null)
    {
        $file = UploadedFile::fake()->createWithContent($name, $content);
        $size = strlen($content);

        return $this->withHeaders(['Accept' => 'application/json', 'Content-Range' => $range ?? 'bytes 0-'.($size - 1).'/'.$size])
            ->post('/staff/uploads', array_merge(['biblio_id' => 42, 'file' => $file], $extra));
    }

    public function test_valid_upload_is_quarantined_and_queued(): void
    {
        $this->actingAsStaff();

        $response = $this->upload('Report.pdf', self::PDF)->assertCreated()->assertJson(['done' => true]);

        $asset = DigitalAsset::where('uuid', $response->json('asset'))->firstOrFail();
        $version = $asset->versions()->first();

        $this->assertTrue($asset->state->equals(Uploaded::class));
        $this->assertSame('document', $asset->media_type->value);
        $this->assertSame('application/pdf', $version->detected_mime);
        $this->assertSame(hash('sha256', self::PDF), $version->checksum_sha256);
        $this->assertSame(VersionStatus::Received, $version->status);
        // Randomised quarantine name; the client filename never reaches disk.
        $this->assertStringNotContainsString('Report', $version->quarantine_path);
        Storage::disk('dam_quarantine')->assertExists($version->quarantine_path);
        Queue::assertPushed(IngestAssetVersion::class);
    }

    public function test_new_asset_gets_the_cached_koha_metadata_snapshot(): void
    {
        $this->actingAsStaff();
        Cache::put('koha:'.config('dam.koha.instance_id').':biblio:42', [
            'biblio_id' => 42, 'title' => 'Renal transplantation', 'author' => 'Rizvi, Adib', 'fetched_at' => now()->toIso8601String(),
        ], 3600);

        $uuid = $this->upload('Report.pdf', self::PDF)->assertCreated()->json('asset');

        $this->assertSame('Renal transplantation', DigitalAsset::where('uuid', $uuid)->first()->snapshot('title'));
    }

    public function test_content_is_validated_not_just_the_extension(): void
    {
        $this->actingAsStaff();

        $this->upload('evil.pdf', '<?php system($_GET["c"]); ?>')->assertUnprocessable();
        $this->upload('shell.php', '<?php echo 1;')->assertUnprocessable();
        $this->upload('page.html', '<html><script>alert(1)</script></html>')->assertUnprocessable();

        $this->assertSame(0, AssetVersion::count());
        Queue::assertNothingPushed();
    }

    public function test_size_limit_is_enforced_from_content_range_and_file(): void
    {
        $this->actingAsStaff();
        config(['dam.upload.max_bytes' => 100]);

        $this->upload('big.pdf', self::PDF, range: 'bytes 0-99/5000000')->assertStatus(413);
        $this->upload('big.pdf', self::PDF)->assertStatus(413);
    }

    public function test_intake_rejects_oversized_files_independently_of_headers(): void
    {
        config(['dam.upload.max_bytes' => 100]);

        $this->expectException(UploadRejectedException::class);
        app(UploadIntake::class)->accept(UploadedFile::fake()->createWithContent('big.pdf', self::PDF), User::factory()->create(), 42);
    }

    public function test_duplicate_on_same_record_is_blocked(): void
    {
        $this->actingAsStaff();
        $this->upload('a.pdf', self::PDF)->assertCreated();

        $this->upload('copy.pdf', self::PDF)->assertUnprocessable()->assertJsonFragment(['message' => 'An identical file is already attached to this record as “a”.']);
    }

    public function test_duplicate_policy_warn_allows_with_warning(): void
    {
        config(['dam.upload.duplicate_policy' => 'warn']);
        $this->actingAsStaff();
        $this->upload('a.pdf', self::PDF)->assertCreated();

        $this->upload('copy.pdf', self::PDF)->assertCreated()->assertJsonCount(1, 'warnings');
    }

    public function test_daily_quota_is_enforced(): void
    {
        config(['dam.upload.daily_quota_bytes' => 10]);
        $this->actingAsStaff();

        $this->upload('a.pdf', self::PDF)->assertUnprocessable();
    }

    public function test_guests_and_unauthorised_roles_cannot_upload(): void
    {
        $this->upload('a.pdf', self::PDF)->assertUnauthorized();

        $this->actingAsStaff('suspended');
        $this->upload('a.pdf', self::PDF)->assertUnauthorized();
    }

    public function test_chunked_upload_assembles_the_file(): void
    {
        $this->actingAsStaff();
        // Chunks are keyed by session, as in a browser: keep one session across requests.
        $this->withCookie(config('session.cookie'), str_repeat('a', 40));
        $content = self::PDF.str_repeat('%padding%', 50);
        $half = intdiv(strlen($content), 2);
        $total = strlen($content);

        $this->upload('chunked.pdf', substr($content, 0, $half), range: 'bytes 0-'.($half - 1)."/{$total}")
            ->assertOk()->assertJson(['done' => false]);

        $response = $this->upload('chunked.pdf', substr($content, $half), range: "bytes {$half}-".($total - 1)."/{$total}");
        $this->assertSame(201, $response->status(), $response->getContent());

        $this->assertSame(hash('sha256', $content), DigitalAsset::where('uuid', $response->json('asset'))->first()->versions()->first()->checksum_sha256);
    }

    public function test_replacement_creates_a_new_version_with_same_asset(): void
    {
        $user = $this->actingAsStaff();
        $asset = DigitalAsset::factory()->processed($this->localBackend())->inState(Published::class)->create(['biblio_id' => 42])->fresh();

        $this->upload('v2.pdf', self::PDF.'%v2', ['replace' => $asset->uuid])->assertCreated()->assertJson(['asset' => $asset->uuid, 'version' => 2]);

        $this->assertSame(2, $asset->versions()->count());
        $this->assertTrue($asset->fresh()->isPublished(), 'The live version stays published while the replacement processes.');
        $this->assertSame($asset->current_version_id, $asset->fresh()->current_version_id);
    }

    public function test_item_level_upload_is_attached_to_the_item(): void
    {
        $this->actingAsStaff();
        FakeKoha::install()->withItem(456, 42, '39999000123');

        $uuid = $this->upload('Copy.pdf', self::PDF, ['item_id' => 456])->assertCreated()->json('asset');

        $asset = DigitalAsset::where('uuid', $uuid)->firstOrFail();
        $this->assertSame(42, $asset->biblio_id);
        $this->assertSame(456, $asset->item_id);
        $this->assertSame('39999000123', $asset->item_snapshot['barcode']);
        $this->assertSame('Item #456', $asset->itemLabel());
    }

    public function test_item_without_a_barcode_can_receive_media(): void
    {
        $this->actingAsStaff();
        FakeKoha::install()->withItem(456, 42);

        $uuid = $this->upload('Copy.pdf', self::PDF, ['item_id' => 456])->assertCreated()->json('asset');

        $asset = DigitalAsset::where('uuid', $uuid)->firstOrFail();
        $this->assertSame(456, $asset->item_id);
        $this->assertSame('Item #456', $asset->itemLabel());
    }

    public function test_item_of_another_record_or_deleted_item_is_refused(): void
    {
        $this->actingAsStaff();
        FakeKoha::install()->withItem(456, 99, '39999000123');

        $this->upload('Copy.pdf', self::PDF, ['item_id' => 456])->assertUnprocessable();
        $this->upload('Copy.pdf', self::PDF, ['item_id' => 457])->assertUnprocessable();
        $this->upload('Copy.pdf', self::PDF, ['item_id' => 'abc'])->assertUnprocessable();
        $this->assertSame(0, DigitalAsset::count());
    }

    public function test_same_file_may_be_attached_to_the_record_and_to_an_item(): void
    {
        $this->actingAsStaff();
        FakeKoha::install()->withItem(456, 42, '39999000123');

        $this->upload('Report.pdf', self::PDF)->assertCreated();
        $this->upload('Report.pdf', self::PDF, ['item_id' => 456])->assertCreated();
        $this->upload('Report.pdf', self::PDF, ['item_id' => 456])->assertUnprocessable();
    }

    public function test_replacement_must_stay_on_the_same_item(): void
    {
        $this->actingAsStaff();
        $asset = DigitalAsset::factory()->processed($this->localBackend())->inState(Published::class)->create(['biblio_id' => 42, 'item_id' => 456])->fresh();

        $this->upload('v2.pdf', self::PDF.'%v2', ['replace' => $asset->uuid])->assertUnprocessable();
        $this->upload('v2.pdf', self::PDF.'%v2', ['replace' => $asset->uuid, 'item_id' => 457])->assertUnprocessable();
        $this->upload('v2.pdf', self::PDF.'%v2', ['replace' => $asset->uuid, 'item_id' => 456])->assertCreated()->assertJson(['asset' => $asset->uuid, 'version' => 2]);
        $this->assertSame(456, $asset->fresh()->item_id);
    }

    public function test_replacement_must_keep_media_type(): void
    {
        $this->actingAsStaff();
        $asset = DigitalAsset::factory()->processed($this->localBackend())->inState(Published::class)->create(['biblio_id' => 42, 'media_type' => 'video'])->fresh();

        $this->upload('v2.pdf', self::PDF, ['replace' => $asset->uuid])->assertUnprocessable();
    }

    public function test_chunk_size_never_exceeds_php_upload_limits(): void
    {
        config(['dam.upload.chunk_bytes' => 512 * 1024]);
        $this->assertSame(512 * 1024, UploadIntake::chunkBytes());

        config(['dam.upload.chunk_bytes' => 1024 * 1024 * 1024]);
        $phpLimit = min(ini_parse_quantity(ini_get('upload_max_filesize')), ini_parse_quantity(ini_get('post_max_size')));
        $this->assertLessThan($phpLimit, UploadIntake::chunkBytes());
    }
}

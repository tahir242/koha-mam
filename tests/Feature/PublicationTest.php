<?php

namespace Tests\Feature;

use App\Enums\PublicationStatus;
use App\Jobs\ProcessPublicationOperation;
use App\Koha\Marc\DataField;
use App\Koha\Marc\Managed856;
use App\Koha\Marc\MarcRecord;
use App\Models\DigitalAsset;
use App\Models\PublicationOperation;
use App\Models\User;
use App\Publishing\AssetWorkflow;
use App\Publishing\PublicationService;
use App\States\Asset\PublicationFailed;
use App\States\Asset\PublicationPending;
use App\States\Asset\Published;
use App\States\Asset\ReadyForReview;
use App\States\Asset\Unpublished;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeKoha;
use Tests\TestCase;

class PublicationTest extends TestCase
{
    use RefreshDatabase;

    private FakeKoha $koha;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->koha = FakeKoha::install()->withRecord(42);
        $this->publisher = User::factory()->role('trusted_cataloguer')->create();
        config(['dam.publishing.default_requires_approval' => false]);
    }

    private function readyAsset(array $attributes = []): DigitalAsset
    {
        return DigitalAsset::factory()->processed($this->localBackend())->inState(ReadyForReview::class)->create(array_merge(['biblio_id' => 42], $attributes))->fresh();
    }

    private function runOutbox(): void
    {
        foreach (PublicationOperation::query()->due()->pluck('id') as $id) {
            (new ProcessPublicationOperation($id))->handle(app(PublicationService::class));
        }
    }

    private function record(): MarcRecord
    {
        return MarcRecord::fromXml($this->koha->records[42]);
    }

    public function test_publication_writes_verified_856_and_marks_published(): void
    {
        $asset = $this->readyAsset();

        app(AssetWorkflow::class)->publish($asset, $this->publisher);
        $this->assertTrue($asset->fresh()->state->equals(PublicationPending::class));
        Queue::assertPushed(ProcessPublicationOperation::class);

        $this->runOutbox();

        $asset->refresh();
        $this->assertTrue($asset->state->equals(Published::class));
        $this->assertNotNull($asset->marc_verified_at);
        $this->assertTrue((new Managed856)->verifyPresent($this->record(), $asset));
        $this->assertSame(PublicationStatus::Succeeded, $asset->publicationOperations()->first()->status);
        $this->assertSame(1, $this->koha->puts);
        // The cataloguing framework is passed through unchanged.
        $this->assertSame(['BKS'], $this->koha->putRequests[0]['headers']['x-framework-id']);
    }

    private function managed856(DigitalAsset $asset): ?DataField
    {
        $fields = array_values(array_filter($this->record()->dataFields('856'), fn ($f) => (new Managed856)->isManagedFor($f, $asset)));

        return $fields[0] ?? null;
    }

    public function test_item_media_is_published_to_the_item_uri_not_the_record(): void
    {
        $this->koha->withItem(456, 42, '39999000123');
        $asset = $this->readyAsset(['item_id' => 456]);

        app(AssetWorkflow::class)->publish($asset, $this->publisher);
        $this->runOutbox();

        $this->assertTrue($asset->fresh()->isPublished());
        $this->assertSame('https://dam.test/item/456', $this->koha->items[456]['uri']);
        $this->assertSame([['item' => 456, 'body' => ['uri' => 'https://dam.test/item/456']]], $this->koha->itemPuts);
        $this->assertNull($this->managed856($asset), 'Item media adds no 856 to the record.');
        $this->assertSame(0, $this->koha->puts);
        $this->assertSame(456, $asset->publicationOperations()->first()->item_id);
        $this->assertSame('39999000123', $asset->fresh()->item_snapshot['barcode']);
    }

    public function test_item_without_a_barcode_is_published_by_item_number(): void
    {
        $this->koha->withItem(456, 42);
        $asset = $this->readyAsset(['item_id' => 456]);

        app(AssetWorkflow::class)->publish($asset, $this->publisher);
        $this->runOutbox();

        $this->assertTrue($asset->fresh()->isPublished());
        $this->assertSame('https://dam.test/item/456', $this->koha->items[456]['uri']);
        $this->assertSame('Item #456', $asset->fresh()->itemLabel());
    }

    public function test_existing_item_uri_is_kept_and_the_link_is_added_once(): void
    {
        $this->koha->withItem(456, 42, 'B1', ['uri' => 'https://publisher.example/ebook']);
        $first = $this->readyAsset(['item_id' => 456]);
        $second = $this->readyAsset(['item_id' => 456]);

        app(AssetWorkflow::class)->publish($first, $this->publisher);
        app(AssetWorkflow::class)->publish($second, $this->publisher);
        $this->runOutbox();

        $this->assertTrue($second->fresh()->isPublished());
        $this->assertSame('https://publisher.example/ebook | https://dam.test/item/456', $this->koha->items[456]['uri']);
        $this->assertCount(1, $this->koha->itemPuts, 'The second asset finds the link already present.');
    }

    public function test_item_link_stays_until_the_last_item_media_is_unpublished(): void
    {
        $this->koha->withItem(456, 42, 'B1', ['uri' => 'https://publisher.example/ebook']);
        $first = $this->readyAsset(['item_id' => 456]);
        $second = $this->readyAsset(['item_id' => 456]);
        app(AssetWorkflow::class)->publish($first, $this->publisher);
        app(AssetWorkflow::class)->publish($second, $this->publisher);
        $this->runOutbox();

        app(AssetWorkflow::class)->unpublish($first->fresh(), $this->publisher);
        $this->runOutbox();
        $this->assertStringContainsString('https://dam.test/item/456', $this->koha->items[456]['uri']);

        app(AssetWorkflow::class)->unpublish($second->fresh(), $this->publisher);
        $this->runOutbox();
        $this->assertSame('https://publisher.example/ebook', $this->koha->items[456]['uri'], 'Only the MAM link is removed.');
        $this->assertSame(PublicationStatus::Succeeded, $second->publicationOperations()->first()->status);
    }

    public function test_unpublishing_media_of_a_deleted_item_succeeds(): void
    {
        $this->koha->withItem(456, 42);
        $asset = $this->readyAsset(['item_id' => 456]);
        app(AssetWorkflow::class)->publish($asset, $this->publisher);
        $this->runOutbox();

        unset($this->koha->items[456]);
        app(AssetWorkflow::class)->unpublish($asset->fresh(), $this->publisher);
        $this->runOutbox();

        $this->assertSame(PublicationStatus::Succeeded, $asset->publicationOperations()->first()->status);
    }

    public function test_publishing_to_a_deleted_or_moved_item_fails_permanently(): void
    {
        $this->koha->withItem(456, 99);
        $asset = $this->readyAsset(['item_id' => 456]);

        app(AssetWorkflow::class)->publish($asset, $this->publisher);
        $this->runOutbox();

        $this->assertTrue($asset->fresh()->state->equals(PublicationFailed::class));
        $this->assertStringContainsString('item', $asset->publicationOperations()->first()->last_error);
        $this->assertSame([], $this->koha->itemPuts);
    }

    public function test_856_written_for_item_media_by_an_earlier_version_is_moved_to_the_item(): void
    {
        $this->koha->withItem(456, 42);
        $asset = $this->readyAsset(['item_id' => 456]);
        $record = $this->record();
        (new Managed856)->apply($record, $asset);
        $this->koha->records[42] = $record->toXml();

        app(AssetWorkflow::class)->publish($asset, $this->publisher);
        $this->runOutbox();

        $this->assertNull($this->managed856($asset));
        $this->assertSame('https://dam.test/item/456', $this->koha->items[456]['uri']);
        $this->assertTrue($asset->publicationOperations()->first()->diagnostics['removed_856']);
    }

    public function test_refused_item_write_keeps_the_old_record_link(): void
    {
        $this->koha->withItem(456, 42)->fail('PUT item', 456, 403);
        $asset = DigitalAsset::factory()->processed($this->localBackend())->inState(Published::class)->create(['biblio_id' => 42, 'item_id' => 456])->fresh();
        $record = $this->record();
        (new Managed856)->apply($record, $asset);
        $this->koha->records[42] = $record->toXml();

        Artisan::call('dam:items:republish');
        $this->runOutbox();

        $this->assertNotNull($this->managed856($asset), 'The 856 stays until the item link is verified.');
        $this->assertSame(PublicationStatus::Failed, $asset->publicationOperations()->first()->status);
        $this->assertStringContainsString('edit_items', $asset->publicationOperations()->first()->last_error);
        $this->assertTrue($asset->fresh()->isPublished());
    }

    public function test_republish_command_moves_published_item_media_to_the_item_uri(): void
    {
        $this->koha->withItem(456, 42);
        $asset = DigitalAsset::factory()->processed($this->localBackend())->inState(Published::class)->create(['biblio_id' => 42, 'item_id' => 456])->fresh();
        $record = $this->record();
        (new Managed856)->apply($record, $asset);
        $this->koha->records[42] = $record->toXml();

        Artisan::call('dam:items:republish');
        $this->runOutbox();

        $this->assertNull($this->managed856($asset));
        $this->assertSame('https://dam.test/item/456', $this->koha->items[456]['uri']);
        $this->assertTrue($asset->fresh()->isPublished());
    }

    public function test_item_page_opens_the_only_resource_or_lists_several(): void
    {
        $this->koha->withItem(456, 42);
        $first = $this->readyAsset(['item_id' => 456, 'title' => 'Scan of copy']);
        app(AssetWorkflow::class)->publish($first, $this->publisher);
        $this->runOutbox();

        $this->get('/item/456')->assertRedirect(route('viewer.show', $first->uuid));

        $second = $this->readyAsset(['item_id' => 456, 'title' => 'Inscription photo']);
        app(AssetWorkflow::class)->publish($second, $this->publisher);
        $this->runOutbox();

        $this->get('/item/456')->assertOk()->assertSeeInOrder(['Inscription photo', 'Scan of copy'])->assertSee('item #456');
        $this->get('/item/999')->assertNotFound();
    }

    public function test_record_level_asset_856_is_unchanged(): void
    {
        $asset = $this->readyAsset();

        app(AssetWorkflow::class)->publish($asset, $this->publisher);
        $this->runOutbox();

        $this->assertSame(['Full text'], $this->managed856($asset)->all('3'));
        $this->assertSame(0, $this->koha->itemReads);
    }

    public function test_existing_marc_fields_and_unrelated_856_are_preserved(): void
    {
        $before = $this->record()->fingerprints(['005', '999']);
        $asset = $this->readyAsset();

        app(AssetWorkflow::class)->publish($asset, $this->publisher);
        $this->runOutbox();

        $after = $this->record()->fingerprints(['005', '999']);

        foreach ($before as $print) {
            $this->assertContains($print, $after);
        }
        $this->assertStringContainsString('https://publisher.example/book', $this->koha->records[42]);
    }

    public function test_republishing_does_not_duplicate_the_managed_link(): void
    {
        $asset = $this->readyAsset();
        $workflow = app(AssetWorkflow::class);

        $workflow->publish($asset, $this->publisher);
        $this->runOutbox();

        // Simulate a lost acknowledgement: the same link operation runs again.
        $asset->refresh();
        PublicationOperation::create(['digital_asset_id' => $asset->id, 'koha_instance_id' => $asset->koha_instance_id, 'biblio_id' => 42, 'action' => 'link', 'status' => PublicationStatus::Pending, 'available_at' => now()]);
        $this->runOutbox();

        $managed = array_filter($this->record()->dataFields('856'), fn ($f) => (new Managed856)->isManagedFor($f, $asset));
        $this->assertCount(1, $managed);
        $this->assertSame(1, $this->koha->puts, 'An unchanged record must not be rewritten.');
        $this->assertTrue($asset->fresh()->state->equals(Published::class));
    }

    public function test_multiple_assets_on_one_record_each_get_a_link(): void
    {
        $a = $this->readyAsset();
        $b = $this->readyAsset(['title' => 'Second']);
        $workflow = app(AssetWorkflow::class);

        $workflow->publish($a, $this->publisher);
        $workflow->publish($b, $this->publisher);
        $this->runOutbox();

        $record = $this->record();
        $this->assertTrue((new Managed856)->verifyPresent($record, $a->fresh()));
        $this->assertTrue((new Managed856)->verifyPresent($record, $b->fresh()));
        $this->assertCount(3, $record->dataFields('856'));
    }

    public function test_koha_unavailable_is_retried_later_and_never_marked_successful(): void
    {
        $this->koha->fail('PUT', 42, 503);
        $asset = $this->readyAsset();

        app(AssetWorkflow::class)->publish($asset, $this->publisher);
        $this->runOutbox();

        $operation = $asset->publicationOperations()->first();
        $this->assertSame(PublicationStatus::Pending, $operation->status);
        $this->assertTrue($operation->available_at->isFuture());
        $this->assertSame('unavailable', $operation->last_error_kind);
        $this->assertTrue($asset->fresh()->state->equals(PublicationPending::class));

        // The relay picks it up again once due, and it succeeds.
        $operation->update(['available_at' => now()->subSecond()]);
        Artisan::call('dam:publications:relay');
        $this->runOutbox();

        $this->assertTrue($asset->fresh()->state->equals(Published::class));
        $this->assertSame(2, $operation->fresh()->attempts);
    }

    public function test_gives_up_after_max_attempts_and_marks_failed(): void
    {
        config(['dam.publishing.max_attempts' => 2]);
        $this->koha->fail('GET', 42, 503, times: 10);
        $asset = $this->readyAsset();

        app(AssetWorkflow::class)->publish($asset, $this->publisher);
        $this->runOutbox();
        PublicationOperation::query()->update(['available_at' => now()->subSecond()]);
        $this->runOutbox();

        $this->assertSame(PublicationStatus::Failed, $asset->publicationOperations()->first()->status);
        $this->assertTrue($asset->fresh()->state->equals(PublicationFailed::class));
    }

    public function test_deleted_koha_record_fails_permanently(): void
    {
        unset($this->koha->records[42]);
        $asset = $this->readyAsset();

        app(AssetWorkflow::class)->publish($asset, $this->publisher);
        $this->runOutbox();

        $operation = $asset->publicationOperations()->first();
        $this->assertSame(PublicationStatus::Failed, $operation->status);
        $this->assertSame('not_found', $operation->last_error_kind);
        $this->assertTrue($asset->fresh()->state->equals(PublicationFailed::class));
    }

    public function test_api_authorization_failure_is_permanent_and_reported(): void
    {
        $this->koha->fail('PUT', 42, 403, times: 2);
        $asset = $this->readyAsset();

        app(AssetWorkflow::class)->publish($asset, $this->publisher);
        $this->runOutbox();

        $this->assertSame('unauthorized', $asset->publicationOperations()->first()->last_error_kind);
        $this->assertTrue($asset->fresh()->state->equals(PublicationFailed::class));
    }

    public function test_concurrent_record_modification_is_detected_and_merged(): void
    {
        $edited = false;
        // After the first MARC read, a cataloguer saves a new 500 note.
        $this->koha->afterMarcRead = function (FakeKoha $koha, int $id) use (&$edited) {
            if (! $edited) {
                $edited = true;
                $koha->records[$id] = str_replace('<datafield tag="942"', '<datafield tag="500" ind1=" " ind2=" "><subfield code="a">Concurrent note.</subfield></datafield><datafield tag="942"', $koha->records[$id]);
            }
        };

        $asset = $this->readyAsset();
        app(AssetWorkflow::class)->publish($asset, $this->publisher);
        $this->runOutbox();

        // The concurrent edit survived and our link was added on top of it.
        $this->assertStringContainsString('Concurrent note.', $this->koha->records[42]);
        $this->assertTrue((new Managed856)->verifyPresent($this->record(), $asset->fresh()));
        $this->assertTrue($asset->fresh()->state->equals(Published::class));
        $this->assertSame(1, $this->koha->puts);
    }

    public function test_failed_read_back_verification_is_not_marked_successful(): void
    {
        $this->koha->dropManagedFieldOnPut = true;
        $asset = $this->readyAsset();

        app(AssetWorkflow::class)->publish($asset, $this->publisher);
        $this->runOutbox();

        $this->assertSame(PublicationStatus::NeedsReview, $asset->publicationOperations()->first()->status);
        $this->assertTrue($asset->fresh()->state->equals(PublicationFailed::class));
        $this->assertNull($asset->fresh()->marc_verified_at);
    }

    public function test_retry_after_failure_succeeds(): void
    {
        $this->koha->fail('PUT', 42, 400);
        $asset = $this->readyAsset();
        $workflow = app(AssetWorkflow::class);

        $workflow->publish($asset, $this->publisher);
        $this->runOutbox();
        $this->assertTrue($asset->fresh()->state->equals(PublicationFailed::class));

        $workflow->retryPublication($asset->fresh(), $this->publisher);
        $this->runOutbox();

        $this->assertTrue($asset->fresh()->state->equals(Published::class));
    }

    public function test_unpublish_revokes_access_immediately_and_removes_only_its_link(): void
    {
        $asset = $this->readyAsset();
        $workflow = app(AssetWorkflow::class);
        $workflow->publish($asset, $this->publisher);
        $this->runOutbox();

        $workflow->unpublish($asset->fresh(), $this->publisher);
        $this->assertTrue($asset->fresh()->state->equals(Unpublished::class));
        $this->get('/view/'.$asset->uuid)->assertNotFound();

        $this->runOutbox();

        $this->assertTrue((new Managed856)->verifyAbsent($this->record(), $asset));
        $this->assertStringContainsString('https://publisher.example/book', $this->koha->records[42]);
    }

    public function test_changing_link_text_on_a_published_asset_refreshes_the_856(): void
    {
        $asset = $this->readyAsset();
        $workflow = app(AssetWorkflow::class);
        $workflow->publish($asset, $this->publisher);
        $this->runOutbox();

        $workflow->updateDetails($asset->fresh(), $this->publisher, ['link_text' => 'Watch the lecture']);
        $this->runOutbox();

        $this->assertStringContainsString('Watch the lecture', $this->koha->records[42]);
        $this->assertTrue((new Managed856)->verifyPresent($this->record(), $asset->fresh()));
    }

    public function test_stale_link_operation_for_unpublished_asset_is_superseded(): void
    {
        $asset = $this->readyAsset();
        app(AssetWorkflow::class)->publish($asset, $this->publisher);
        $asset->refresh()->state->transitionTo(Unpublished::class);

        $this->runOutbox();

        $this->assertSame(0, $this->koha->puts);
        $this->assertSame(PublicationStatus::Superseded, $asset->publicationOperations()->first()->status);
    }
}

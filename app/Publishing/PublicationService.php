<?php

namespace App\Publishing;

use App\Enums\PublicationStatus;
use App\Koha\BiblioRepository;
use App\Koha\Exceptions\KohaAuthorizationException;
use App\Koha\Exceptions\KohaConflictException;
use App\Koha\Exceptions\KohaException;
use App\Koha\Exceptions\KohaRecordNotFoundException;
use App\Koha\ItemRepository;
use App\Koha\KohaClient;
use App\Koha\ManagedItemUri;
use App\Koha\Marc\DataField;
use App\Koha\Marc\Managed856;
use App\Koha\Marc\MarcRecord;
use App\Models\DigitalAsset;
use App\Models\PublicationOperation;
use App\States\Asset\PublicationFailed;
use App\States\Asset\PublicationPending;
use App\States\Asset\Published;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Executes publication outbox operations against Koha:
 *
 *   fetch live MARC → apply managed 856 change → detect concurrent edits →
 *   PUT → read back → verify our field and the preservation of every
 *   other field → mark the asset published.
 *
 * A failure is never recorded as success. Transient failures are retried
 * with backoff; permanent ones surface on the dashboard for staff action.
 */
class PublicationService
{
    private const CONFLICT_RETRIES = 3;

    public function __construct(
        private readonly KohaClient $koha,
        private readonly Managed856 $managed,
        private readonly BiblioRepository $biblios,
        private readonly ItemRepository $items,
        private readonly ManagedItemUri $itemUri,
    ) {}

    public function process(PublicationOperation $operation): void
    {
        try {
            // One writer per bibliographic record at a time.
            Cache::lock('koha:biblio-write:'.$operation->koha_instance_id.':'.$operation->biblio_id, 300)
                ->block(30, fn () => $this->run($operation->fresh()));
        } catch (LockTimeoutException) {
            $this->reschedule($operation, 'Another update to this record is in progress.', 'locked', countAttempt: false);
        }
    }

    private function run(PublicationOperation $operation): void
    {
        if ($operation->status !== PublicationStatus::Pending) {
            return;
        }

        $asset = $operation->asset;

        if ($this->isStale($operation, $asset)) {
            $operation->update(['status' => PublicationStatus::Superseded, 'completed_at' => now(), 'last_error' => 'Asset state changed before the operation ran.']);

            return;
        }

        $operation->update(['status' => PublicationStatus::Running, 'attempts' => $operation->attempts + 1, 'started_at' => now()]);

        try {
            $this->execute($operation, $asset);
        } catch (KohaRecordNotFoundException $e) {
            $this->failPermanently($operation, $asset, $asset->item_id !== null
                ? 'The Koha item no longer exists or now belongs to another record.'
                : 'The Koha bibliographic record no longer exists.', $e->kind());
        } catch (KohaException $e) {
            $e->isTransient()
                ? $this->reschedule($operation, $e->getMessage(), $e->kind())
                : $this->failPermanently($operation, $asset, $e->getMessage(), $e->kind());
        } catch (InvalidArgumentException $e) {
            $this->failPermanently($operation, $asset, $e->getMessage(), 'invalid_marc');
        } catch (Throwable $e) {
            Log::error('Unexpected publication failure', ['operation' => $operation->uuid, 'exception' => $e]);
            $this->reschedule($operation, 'Unexpected error: '.$e->getMessage(), 'internal');
        }
    }

    private function execute(PublicationOperation $operation, DigitalAsset $asset): void
    {
        if ($asset->item_id !== null) {
            $this->executeItem($operation, $asset);

            return;
        }

        $link = $operation->action === PublicationOperation::ACTION_LINK;

        for ($attempt = 1; $attempt <= self::CONFLICT_RETRIES; $attempt++) {
            $json = $this->koha->getBiblioJson($operation->biblio_id);
            $before = MarcRecord::fromXml($this->koha->getBiblioMarcXml($operation->biblio_id));
            $beforeHash = $before->hash();
            $preserved = $this->preservedFingerprints($before, $asset);

            $working = MarcRecord::fromXml($before->toXml());
            $changed = $link ? $this->managed->apply($working, $asset) : $this->managed->remove($working, $asset);

            $operation->forceFill([
                'marc_005_before' => $before->controlField('005'),
                'marc_hash_before' => $beforeHash,
                'field_856' => $link ? $this->managed->build($asset)->toArray() : null,
            ])->save();

            if ($changed) {
                // Optimistic concurrency: re-read immediately before writing.
                $current = MarcRecord::fromXml($this->koha->getBiblioMarcXml($operation->biblio_id));

                if ($current->hash() !== $beforeHash) {
                    Log::info('Koha record changed during publication; retrying with fresh data.', ['operation' => $operation->uuid, 'attempt' => $attempt]);

                    continue;
                }

                $this->koha->putBiblioMarcXml($operation->biblio_id, $working->toXml(), $json['framework_id'] ?? null);
            }

            $this->verify($operation, $asset, $preserved, $changed);

            return;
        }

        throw new KohaConflictException('The Koha record kept changing while it was being updated.');
    }

    /**
     * @param  list<string>  $preserved
     */
    private function verify(PublicationOperation $operation, DigitalAsset $asset, array $preserved, bool $changed): void
    {
        $link = $operation->action === PublicationOperation::ACTION_LINK;
        $after = MarcRecord::fromXml($this->koha->getBiblioMarcXml($operation->biblio_id));

        $fieldOk = $link ? $this->managed->verifyPresent($after, $asset) : $this->managed->verifyAbsent($after, $asset);
        $missing = $this->missingFingerprints($preserved, $this->preservedFingerprints($after, $asset));

        $operation->forceFill([
            'marc_005_after' => $after->controlField('005'),
            'marc_hash_after' => $after->hash(),
            'diagnostics' => ['wrote' => $changed, 'field_verified' => $fieldOk, 'missing_fields' => array_slice($missing, 0, 50)],
        ])->save();

        if (! $fieldOk) {
            $this->needsReview($operation, $asset, $link
                ? 'Koha accepted the update but the managed 856 field was not found when the record was read back.'
                : 'The managed 856 field is still present after removal.');

            return;
        }

        DB::transaction(function () use ($operation, $asset, $link, $missing) {
            $operation->update([
                'status' => $missing === [] ? PublicationStatus::Succeeded : PublicationStatus::NeedsReview,
                'completed_at' => now(),
                'last_error' => $missing === [] ? null : count($missing).' pre-existing field(s) differ after the update; review the record in Koha.',
                'last_error_kind' => $missing === [] ? null : 'preservation_check',
            ]);

            if ($link) {
                $asset->forceFill(['marc_verified_at' => now(), 'published_at' => $asset->published_at ?? now()])->save();

                if ($asset->state->equals(PublicationPending::class)) {
                    $asset->state->transitionTo(Published::class);
                }
            }
        });

        // Refresh the cached metadata and every asset's snapshot for this record
        // (the public viewer shows the snapshot). Never fails the publication.
        try {
            $this->biblios->find($operation->biblio_id, refresh: true);
        } catch (Throwable) {
            $this->biblios->forget($operation->biblio_id);
        }

        activity('publication')->performedOn($asset)->causedBy($operation->requester)->event($link ? 'koha_linked' : 'koha_unlinked')
            ->withProperties(['biblio_id' => $operation->biblio_id, 'operation' => $operation->uuid, 'wrote' => $operation->diagnostics['wrote'] ?? null, 'missing_fields' => count($missing)])
            ->log($link ? 'MARC 856 link written and verified in Koha' : 'MARC 856 link removed from Koha');

        if ($missing !== []) {
            Log::warning('Koha record differs after publication', ['operation' => $operation->uuid, 'missing' => $missing]);
        }
    }

    /**
     * Item-level media is shown in the holdings table through the item's
     * URI field (items.uri, 952$u), not as an 856 of the record: one MAM link
     * per item, present while at least one of its media is published.
     *
     *   read item → add/remove the MAM link (keeping other URIs) → PUT →
     *   read back → verify → remove any 856 an earlier version wrote for this
     *   asset (only now, so a refused item write never loses the link).
     */
    private function executeItem(PublicationOperation $operation, DigitalAsset $asset): void
    {
        $link = $operation->action === PublicationOperation::ACTION_LINK;

        try {
            $item = $this->koha->getItem($asset->item_id);

            if ((int) ($item['biblio_id'] ?? 0) !== $asset->biblio_id) {
                throw new KohaRecordNotFoundException("Koha item {$asset->item_id} does not belong to record {$asset->biblio_id}.", 404);
            }
        } catch (KohaRecordNotFoundException $e) {
            if ($link) {
                throw $e;
            }

            // Nothing left to unlink: the item (and its URI) is gone from this record.
            $removed856 = $this->removeRecordLink($operation, $asset);
            $operation->forceFill(['diagnostics' => ['target' => 'item_uri', 'item_missing' => true, 'removed_856' => $removed856]])->save();
            $this->complete($operation, $asset, 'Item media link removed (the Koha item no longer exists)');

            return;
        }

        $this->items->remember($asset->item_id, $item);

        // The link stays while any other published media of the item still needs it.
        $keep = $link || DigitalAsset::query()->forItem($asset->item_id)->published()->whereKeyNot($asset->id)->exists();
        $before = filled($item['uri'] ?? null) ? $item['uri'] : null;
        $after = $keep ? $this->itemUri->with($before, $asset->item_id) : $this->itemUri->without($before, $asset->item_id);
        $wrote = $after !== $before;

        if ($wrote) {
            try {
                $this->koha->updateItem($asset->biblio_id, $asset->item_id, ['uri' => $after]);
            } catch (KohaAuthorizationException $e) {
                throw new KohaAuthorizationException($e->getMessage().' The Koha API user needs the editcatalogue: edit_items permission to write the item URI (952$u).', $e->status, $e);
            }
        }

        $readBack = $this->koha->getItem($asset->item_id)['uri'] ?? null;
        $verified = $this->itemUri->has($readBack, $asset->item_id) === $keep;

        $diagnostics = ['target' => 'item_uri', 'wrote' => $wrote, 'field_verified' => $verified, 'uri_before' => $before, 'uri_after' => $readBack];
        $operation->forceFill(['diagnostics' => $diagnostics])->save();

        if (! $verified) {
            $this->needsReview($operation, $asset, $keep
                ? 'Koha accepted the update but the item URI (952$u) did not contain the MAM link when the item was read back.'
                : 'The MAM link is still in the item URI (952$u) after removal.');

            return;
        }

        $operation->forceFill(['diagnostics' => $diagnostics + ['removed_856' => $this->removeRecordLink($operation, $asset)]])->save();

        $this->complete($operation, $asset, $link ? 'Item URI (952$u) link written and verified in Koha' : 'Item URI (952$u) link removed from Koha');
    }

    /** Remove an 856 that plugin/MAM 2.5.0 wrote to the record for item media. Returns true when the record changed. */
    private function removeRecordLink(PublicationOperation $operation, DigitalAsset $asset): bool
    {
        for ($attempt = 1; $attempt <= self::CONFLICT_RETRIES; $attempt++) {
            $before = MarcRecord::fromXml($this->koha->getBiblioMarcXml($operation->biblio_id));
            $working = MarcRecord::fromXml($before->toXml());

            if (! $this->managed->remove($working, $asset)) {
                return false;
            }

            $json = $this->koha->getBiblioJson($operation->biblio_id);

            if (MarcRecord::fromXml($this->koha->getBiblioMarcXml($operation->biblio_id))->hash() !== $before->hash()) {
                continue;
            }

            $this->koha->putBiblioMarcXml($operation->biblio_id, $working->toXml(), $json['framework_id'] ?? null);

            return true;
        }

        throw new KohaConflictException('The Koha record kept changing while it was being updated.');
    }

    private function complete(PublicationOperation $operation, DigitalAsset $asset, string $message): void
    {
        $link = $operation->action === PublicationOperation::ACTION_LINK;

        DB::transaction(function () use ($operation, $asset, $link) {
            $operation->update(['status' => PublicationStatus::Succeeded, 'completed_at' => now(), 'last_error' => null, 'last_error_kind' => null]);

            if ($link) {
                $asset->forceFill(['marc_verified_at' => now(), 'published_at' => $asset->published_at ?? now()])->save();

                if ($asset->state->equals(PublicationPending::class)) {
                    $asset->state->transitionTo(Published::class);
                }
            }
        });

        activity('publication')->performedOn($asset)->causedBy($operation->requester)->event($link ? 'koha_linked' : 'koha_unlinked')
            ->withProperties(['biblio_id' => $operation->biblio_id, 'item_id' => $asset->item_id, 'operation' => $operation->uuid, 'wrote' => $operation->diagnostics['wrote'] ?? null])
            ->log($message);
    }

    /**
     * Fingerprints of every field that must survive the update: everything
     * except volatile fields and this asset's own managed 856.
     *
     * @return list<string>
     */
    private function preservedFingerprints(MarcRecord $record, DigitalAsset $asset): array
    {
        $ignore = config('dam.publishing.marc.volatile_tags');
        $own = array_map(fn (DataField $f) => $f->fingerprint(), array_filter($record->dataFields('856'), fn (DataField $f) => $this->managed->isManagedFor($f, $asset)));

        $prints = $record->fingerprints($ignore);

        foreach ($own as $fingerprint) {
            $index = array_search($fingerprint, $prints, true);

            if ($index !== false) {
                unset($prints[$index]);
            }
        }

        return array_values($prints);
    }

    /**
     * Multiset difference: fingerprints from $before not present in $after.
     *
     * @return list<string>
     */
    private function missingFingerprints(array $before, array $after): array
    {
        $counts = array_count_values($after);
        $missing = [];

        foreach ($before as $print) {
            if (($counts[$print] ?? 0) > 0) {
                $counts[$print]--;
            } else {
                $missing[] = Str::limit($print, 200);
            }
        }

        return $missing;
    }

    private function isStale(PublicationOperation $operation, DigitalAsset $asset): bool
    {
        if ($asset->trashed()) {
            return $operation->action === PublicationOperation::ACTION_LINK;
        }

        return $operation->action === PublicationOperation::ACTION_LINK
            ? ! $asset->state->equals(PublicationPending::class, Published::class)
            : $asset->state->equals(PublicationPending::class, Published::class);
    }

    private function reschedule(PublicationOperation $operation, string $error, string $kind, bool $countAttempt = true): void
    {
        $operation->refresh();
        $max = (int) config('dam.publishing.max_attempts');

        if ($countAttempt && $operation->attempts >= $max) {
            $this->failPermanently($operation, $operation->asset, "Gave up after {$operation->attempts} attempts: {$error}", $kind);

            return;
        }

        $backoff = config('dam.publishing.backoff');
        $delay = $backoff[min(max(0, $operation->attempts - 1), count($backoff) - 1)];

        $operation->update([
            'status' => PublicationStatus::Pending,
            'available_at' => now()->addSeconds($delay),
            'last_error' => Str::limit($error, 2000),
            'last_error_kind' => $kind,
        ]);

        Log::warning('Koha publication deferred', ['operation' => $operation->uuid, 'kind' => $kind, 'retry_in' => $delay]);
    }

    private function failPermanently(PublicationOperation $operation, DigitalAsset $asset, string $error, string $kind): void
    {
        DB::transaction(function () use ($operation, $asset, $error, $kind) {
            $operation->update(['status' => PublicationStatus::Failed, 'completed_at' => now(), 'last_error' => Str::limit($error, 2000), 'last_error_kind' => $kind]);

            if ($operation->action === PublicationOperation::ACTION_LINK && $asset->state->equals(PublicationPending::class)) {
                $asset->state->transitionTo(PublicationFailed::class);
            }
        });

        activity('publication')->performedOn($asset)->event('koha_publication_failed')
            ->withProperties(['biblio_id' => $operation->biblio_id, 'operation' => $operation->uuid, 'kind' => $kind, 'error' => Str::limit($error, 500)])
            ->log($operation->action === PublicationOperation::ACTION_LINK ? 'Koha publication failed' : 'Removing the Koha link failed');
    }

    private function needsReview(PublicationOperation $operation, DigitalAsset $asset, string $error): void
    {
        DB::transaction(function () use ($operation, $asset, $error) {
            $operation->update(['status' => PublicationStatus::NeedsReview, 'completed_at' => now(), 'last_error' => $error, 'last_error_kind' => 'verification']);

            if ($operation->action === PublicationOperation::ACTION_LINK && $asset->state->equals(PublicationPending::class)) {
                $asset->state->transitionTo(PublicationFailed::class);
            }
        });

        activity('publication')->performedOn($asset)->event('koha_verification_failed')
            ->withProperties(['biblio_id' => $operation->biblio_id, 'operation' => $operation->uuid, 'error' => $error])
            ->log('Koha read-back verification failed');
    }
}

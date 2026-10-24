<?php

namespace App\Livewire;

use App\Assets\UploadIntake;
use App\Enums\Capability;
use App\Koha\BiblioMetadata;
use App\Koha\BiblioRepository;
use App\Koha\Exceptions\KohaException;
use App\Koha\Exceptions\KohaRecordNotFoundException;
use App\Koha\ItemMetadata;
use App\Koha\ItemRepository;
use App\Media\MediaTypeRegistry;
use App\Models\DigitalAsset;
use App\States\Asset\PublicationPending;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Context-aware workspace for one Koha bibliographic record: metadata from
 * Koha (cached), the record's media assets and the uploader. Opened from
 * a Koha item (?item=), it attaches uploads to that item of the record.
 */
class BiblioWorkspace extends Component
{
    public int $biblio;

    /** Koha itemnumber when the workspace was opened for one item of the record. */
    public ?int $item = null;

    public ?array $metadata = null;

    public ?array $itemMetadata = null;

    public ?string $kohaError = null;

    public bool $notFound = false;

    public ?string $itemError = null;

    public bool $itemNotFound = false;

    public function mount(int $biblio, BiblioRepository $repository, ItemRepository $items): void
    {
        $this->authorize(Capability::ViewDashboard->value);
        $this->biblio = $biblio;
        $item = request()->query('item');
        $this->item = is_string($item) && preg_match('/^[1-9][0-9]{0,18}$/', $item) ? (int) $item : null;
        $this->load($repository);
        $this->loadItem($items);
    }

    public function refreshMetadata(BiblioRepository $repository, ItemRepository $items): void
    {
        $this->load($repository, refresh: true);
        $this->loadItem($items, refresh: true);
    }

    #[On('asset-uploaded')]
    public function assetUploaded(): void
    {
        // Re-render; the poll keeps processing status current afterwards.
    }

    private function load(BiblioRepository $repository, bool $refresh = false): void
    {
        $this->kohaError = null;
        $this->notFound = false;

        try {
            $meta = $repository->find($this->biblio, $refresh);
            $this->metadata = $meta->toArray() + ['from_cache' => $meta->fromCache];
        } catch (KohaRecordNotFoundException) {
            $this->notFound = true;
            $this->metadata = null;
        } catch (KohaException $e) {
            $this->kohaError = $e->getMessage();
            // Fall back to the snapshot stored with existing assets, if any.
            $snapshot = DigitalAsset::query()->forBiblio($this->biblio)->whereNotNull('biblio_snapshot')->latest('biblio_snapshot_at')->value('biblio_snapshot');
            $this->metadata = $snapshot ? (is_array($snapshot) ? $snapshot : json_decode($snapshot, true)) + ['from_cache' => true] : null;
        }
    }

    /**
     * Read the item fresh on open, so a barcode assigned since the media was
     * attached shows here.
     */
    private function loadItem(ItemRepository $items, bool $refresh = true): void
    {
        $this->itemError = null;
        $this->itemNotFound = false;

        if ($this->item === null) {
            return;
        }

        try {
            $this->itemMetadata = $items->find($this->item, $this->biblio, $refresh);
        } catch (KohaRecordNotFoundException) {
            $this->itemNotFound = true;
            $this->itemMetadata = DigitalAsset::query()->forItem($this->item)->whereNotNull('item_snapshot')->latest('item_snapshot_at')->value('item_snapshot');
            $this->itemMetadata = is_string($this->itemMetadata) ? json_decode($this->itemMetadata, true) : $this->itemMetadata;
        } catch (KohaException $e) {
            $this->itemError = $e->getMessage();
            $this->itemMetadata = $items->cached($this->item);
        }
    }

    public function render(MediaTypeRegistry $registry)
    {
        $assets = DigitalAsset::query()->forBiblio($this->biblio)->with(['currentVersion.files', 'creator', 'versions' => fn ($q) => $q->limit(1)])->latest()->get();
        $processing = $assets->contains(fn (DigitalAsset $a) => $a->state->isProcessing() || $a->state->equals(PublicationPending::class)
            || $a->versions->first()?->status?->isTerminal() === false);

        // Opened for an item: that item's media first, then the rest of the record.
        [$itemAssets, $otherAssets] = $this->item === null
            ? [collect(), $assets]
            : $assets->partition(fn (DigitalAsset $a) => $a->item_id === $this->item);

        // An unknown or moved item cannot receive new uploads; the record still can.
        $uploadToItem = $this->item !== null && ! $this->itemNotFound && ($this->itemMetadata !== null || $this->itemError === null);

        return view('livewire.biblio-workspace', [
            'meta' => $this->metadata ? BiblioMetadata::fromArray($this->metadata, (bool) ($this->metadata['from_cache'] ?? false)) : null,
            'assets' => $assets,
            'itemAssets' => $itemAssets->values(),
            'otherAssets' => $otherAssets->values(),
            'itemLabel' => $this->item === null ? null : ItemMetadata::label($this->item),
            'itemDetails' => $this->item === null ? null : ItemMetadata::details($this->itemMetadata),
            'uploadToItem' => $uploadToItem,
            'processing' => $processing,
            'uploader' => [
                'endpoint' => route('uploads.store'),
                'biblioId' => $this->biblio,
                'itemId' => $uploadToItem ? $this->item : null,
                'csrf' => csrf_token(),
                'chunkBytes' => UploadIntake::chunkBytes(),
                'maxBytes' => (int) config('dam.upload.max_bytes'),
                'extensions' => $registry->allowedExtensions(),
            ],
            'extensionsByType' => $registry->extensionsByType(),
        ])->title($this->metadata['title'] ?? "Record {$this->biblio}");
    }
}

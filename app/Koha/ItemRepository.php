<?php

namespace App\Koha;

use App\Koha\Exceptions\KohaException;
use App\Koha\Exceptions\KohaRecordNotFoundException;
use App\Models\DigitalAsset;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cached read access to Koha items, for item-level attachment. The asset is
 * linked by itemnumber; the snapshot only provides display details
 * (barcode, copy and call number), refreshed on every fresh read.
 */
class ItemRepository
{
    public function __construct(private readonly KohaClient $client) {}

    /**
     * The item's snapshot, verified to belong to the given record.
     *
     * @return array{item_id: int, biblio_id: int, barcode: ?string, copy_number: ?string, callnumber: ?string, home_library_id: ?string}
     *
     * @throws KohaRecordNotFoundException when the item no longer exists or belongs to another record
     * @throws KohaException when Koha is unreachable and nothing is cached
     */
    public function find(int $itemId, int $biblioId, bool $refresh = false): array
    {
        $key = $this->cacheKey($itemId);
        $snapshot = $refresh ? null : Cache::get($key);

        if (! $snapshot) {
            try {
                $snapshot = $this->remember($itemId, $this->client->getItem($itemId));
            } catch (KohaRecordNotFoundException $e) {
                Cache::forget($key);
                Cache::forget($key.':stale');

                throw $e;
            } catch (KohaException $e) {
                // Serve the last known copy rather than failing.
                if (! ($snapshot = Cache::get($key.':stale'))) {
                    throw $e;
                }

                Log::warning('Koha unavailable; serving cached item metadata.', ['item_id' => $itemId, 'error' => $e->getMessage()]);
            }
        }

        if ($snapshot['biblio_id'] !== $biblioId) {
            throw new KohaRecordNotFoundException("Koha item {$itemId} does not belong to record {$biblioId}.", 404);
        }

        return $snapshot;
    }

    /**
     * Cache a fresh item read (Koha JSON) and copy it to the item's assets.
     *
     * @return array{item_id: int, biblio_id: int, barcode: ?string, copy_number: ?string, callnumber: ?string, home_library_id: ?string}
     */
    public function remember(int $itemId, array $json): array
    {
        $snapshot = ItemMetadata::fromKoha($itemId, $json);
        $key = $this->cacheKey($itemId);

        Cache::put($key, $snapshot, config('dam.koha.metadata_cache_ttl'));
        Cache::forever($key.':stale', $snapshot);
        DigitalAsset::query()->forItem($itemId)->update(['item_snapshot' => json_encode($snapshot), 'item_snapshot_at' => now()]);

        return $snapshot;
    }

    /** Last known snapshot without contacting Koha. */
    public function cached(int $itemId): ?array
    {
        $key = $this->cacheKey($itemId);

        return Cache::get($key) ?? Cache::get($key.':stale');
    }

    private function cacheKey(int $itemId): string
    {
        return 'koha:'.config('dam.koha.instance_id').':item:'.$itemId;
    }
}

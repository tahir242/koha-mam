<?php

namespace App\Koha;

use App\Koha\Exceptions\KohaException;
use App\Koha\Exceptions\KohaRecordNotFoundException;
use App\Koha\Marc\MarcRecord;
use App\Models\DigitalAsset;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Cached read access to Koha bibliographic metadata. Koha stays the source
 * of truth; the cache only shields the API from repeated reads. Publication
 * always bypasses this class and reads the live record.
 */
class BiblioRepository
{
    public function __construct(private readonly KohaClient $client) {}

    /**
     * @throws KohaRecordNotFoundException when the record no longer exists
     * @throws KohaException when Koha is unreachable and nothing is cached
     */
    public function find(int $biblioId, bool $refresh = false): BiblioMetadata
    {
        $key = $this->cacheKey($biblioId);

        if (! $refresh && ($cached = Cache::get($key))) {
            return BiblioMetadata::fromArray($cached, fromCache: true);
        }

        try {
            $metadata = $this->fetch($biblioId);
        } catch (KohaRecordNotFoundException $e) {
            Cache::forget($key);
            Cache::forget($key.':stale');

            throw $e;
        } catch (KohaException $e) {
            // Serve the last known copy (clearly marked as cached) rather than failing.
            if ($stale = Cache::get($key.':stale')) {
                Log::warning('Koha unavailable; serving cached bibliographic metadata.', ['biblio_id' => $biblioId, 'error' => $e->getMessage()]);

                return BiblioMetadata::fromArray($stale, fromCache: true);
            }

            throw $e;
        }

        Cache::put($key, $metadata->toArray(), config('dam.koha.metadata_cache_ttl'));
        Cache::forever($key.':stale', $metadata->toArray());

        DigitalAsset::query()->forBiblio($biblioId)->update([
            'biblio_snapshot' => json_encode($metadata->toArray()),
            'biblio_snapshot_at' => now(),
        ]);

        return $metadata;
    }

    /**
     * Last known metadata without contacting Koha (fresh cache, else the
     * stale copy), used to give a newly created asset its snapshot.
     */
    public function cached(int $biblioId): ?array
    {
        $key = $this->cacheKey($biblioId);

        return Cache::get($key) ?? Cache::get($key.':stale');
    }

    public function forget(int $biblioId): void
    {
        Cache::forget($this->cacheKey($biblioId));
    }

    private function fetch(int $biblioId): BiblioMetadata
    {
        $json = $this->client->getBiblioJson($biblioId);

        try {
            $marc = MarcRecord::fromXml($this->client->getBiblioMarcXml($biblioId));
        } catch (InvalidArgumentException) {
            $marc = null;
        }

        return BiblioMetadata::fromKoha($biblioId, $json, $marc);
    }

    private function cacheKey(int $biblioId): string
    {
        return 'koha:'.config('dam.koha.instance_id').':biblio:'.$biblioId;
    }
}

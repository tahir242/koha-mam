<?php

namespace App\Koha;

use App\Koha\Exceptions\KohaAuthorizationException;
use App\Koha\Exceptions\KohaConflictException;
use App\Koha\Exceptions\KohaException;
use App\Koha\Exceptions\KohaRecordNotFoundException;
use App\Koha\Exceptions\KohaRejectedException;
use App\Koha\Exceptions\KohaUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the Koha REST API (v1) endpoints this application uses:
 *
 *  - POST /api/v1/oauth/token          (client credentials)
 *  - GET  /api/v1/biblios/{id}         (application/json, application/marcxml+xml)
 *  - PUT  /api/v1/biblios/{id}         (application/marcxml+xml)
 *  - GET  /api/v1/items/{id}           (item-level attachment: owning record, barcode, uri)
 *  - PUT  /api/v1/biblios/{id}/items/{item_id}  (item-level publication: items.uri = 952$u)
 *  - GET  /api/v1/patrons/{id}         (optional SSO identity verification)
 *
 * It never touches the Koha database directly.
 */
class KohaClient
{
    public function __construct(private readonly array $config) {}

    public static function fromConfig(): self
    {
        return new self(config('dam.koha'));
    }

    public function isConfigured(): bool
    {
        $auth = $this->config['auth'];

        return filled($this->config['staff_url']) && match ($auth['method']) {
            'basic' => filled($auth['username']) && filled($auth['password']),
            default => filled($auth['client_id']) && filled($auth['client_secret']),
        };
    }

    /** Bibliographic record as Koha's JSON representation. */
    public function getBiblioJson(int $biblioId): array
    {
        return $this->send('get', "/biblios/{$biblioId}", accept: 'application/json')->json() ?? [];
    }

    /** Bibliographic record as MARCXML (always a fresh read). */
    public function getBiblioMarcXml(int $biblioId): string
    {
        return $this->send('get', "/biblios/{$biblioId}", accept: 'application/marcxml+xml')->body();
    }

    /** Item as Koha's JSON representation (item_id, biblio_id, external_id = barcode, ...). */
    public function getItem(int $itemId): array
    {
        return $this->send('get', "/items/{$itemId}", accept: 'application/json')->json() ?? [];
    }

    /**
     * Update the given item fields only (Koha applies the body with
     * set_from_api, leaving other fields untouched). Koha answers 404 when
     * the item does not belong to the record.
     */
    public function updateItem(int $biblioId, int $itemId, array $fields): array
    {
        return $this->send('put', "/biblios/{$biblioId}/items/{$itemId}", accept: 'application/json', body: json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), contentType: 'application/json')->json() ?? [];
    }

    /**
     * Replace the record with the given MARCXML. The framework is passed
     * through explicitly so the update never moves a record to another
     * cataloguing framework.
     */
    public function putBiblioMarcXml(int $biblioId, string $marcXml, ?string $frameworkId): array
    {
        $headers = ['x-record-schema' => $this->config['record_schema']];

        if ($frameworkId !== null) {
            $headers['x-framework-id'] = $frameworkId;
        }

        return $this->send('put', "/biblios/{$biblioId}", accept: 'application/json', body: $marcXml, contentType: 'application/marcxml+xml', headers: $headers)->json() ?? [];
    }

    /**
     * Lightweight connectivity check used by diagnostics and health checks.
     *
     * @return array{ok: bool, message: string, latency_ms: int|null}
     */
    public function ping(?int $biblioId = null): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'message' => 'Koha API is not configured.', 'latency_ms' => null];
        }

        $started = microtime(true);

        try {
            $this->accessToken(forceRefresh: true);

            if ($biblioId) {
                $this->getBiblioJson($biblioId);
            }

            return ['ok' => true, 'message' => 'Connected', 'latency_ms' => (int) ((microtime(true) - $started) * 1000)];
        } catch (KohaRecordNotFoundException) {
            return ['ok' => true, 'message' => 'Connected (probe record not found)', 'latency_ms' => (int) ((microtime(true) - $started) * 1000)];
        } catch (KohaException $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'latency_ms' => null];
        }
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->config['staff_url'].'/api/v1')
            ->timeout($this->config['timeout'])
            ->connectTimeout($this->config['connect_timeout'])
            ->withOptions(['verify' => filter_var($this->config['verify_tls'], FILTER_VALIDATE_BOOL)])
            ->withUserAgent('SIUT-MAM/1.0');
    }

    private function authorized(): PendingRequest
    {
        $auth = $this->config['auth'];

        if ($auth['method'] === 'basic') {
            return $this->request()->withBasicAuth($auth['username'], $auth['password']);
        }

        return $this->request()->withToken($this->accessToken());
    }

    private function accessToken(bool $forceRefresh = false): ?string
    {
        $auth = $this->config['auth'];

        if ($auth['method'] === 'basic') {
            return null;
        }

        $cacheKey = 'koha:token:'.hash('sha256', $this->config['staff_url'].'|'.$auth['client_id']);

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        if ($token = Cache::get($cacheKey)) {
            return $token;
        }

        try {
            $response = $this->request()->asForm()->post('/oauth/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $auth['client_id'],
                'client_secret' => $auth['client_secret'],
            ]);
        } catch (ConnectionException $e) {
            throw new KohaUnavailableException('Koha API is unreachable: '.$e->getMessage(), null, $e);
        }

        if ($response->serverError() || $response->status() === 429) {
            throw new KohaUnavailableException("Koha token endpoint returned HTTP {$response->status()}.", $response->status());
        }

        if (! $response->successful() || ! $response->json('access_token')) {
            throw new KohaAuthorizationException('Koha rejected the API client credentials (check RESTOAuth2ClientCredentials and the API key).', $response->status());
        }

        $ttl = max(60, (int) $response->json('expires_in', 3600) - 60);
        Cache::put($cacheKey, $response->json('access_token'), $ttl);

        return $response->json('access_token');
    }

    private function send(string $method, string $uri, string $accept, ?string $body = null, ?string $contentType = null, array $headers = [], bool $retriedAuth = false): Response
    {
        try {
            $request = $this->authorized()->accept($accept)->withHeaders($headers);

            if ($body !== null) {
                $request = $request->withBody($body, $contentType);
            }

            $response = $request->send(strtoupper($method), $uri);
        } catch (ConnectionException $e) {
            throw new KohaUnavailableException('Koha API is unreachable: '.$e->getMessage(), null, $e);
        }

        // An expired cached token: refresh once.
        if ($response->status() === 401 && ! $retriedAuth && $this->config['auth']['method'] !== 'basic') {
            $this->accessToken(forceRefresh: true);

            return $this->send($method, $uri, $accept, $body, $contentType, $headers, true);
        }

        if ($response->successful()) {
            return $response;
        }

        $detail = $this->errorDetail($response);

        throw match (true) {
            $response->status() === 404 => new KohaRecordNotFoundException("Koha returned 404 for {$uri}.", 404),
            $response->status() === 409 => new KohaConflictException("Koha reported a conflict: {$detail}", 409),
            in_array($response->status(), [401, 403], true) => new KohaAuthorizationException("Koha denied access to {$uri} (HTTP {$response->status()}): {$detail}", $response->status()),
            $response->status() === 429 || $response->serverError() => new KohaUnavailableException("Koha returned HTTP {$response->status()}: {$detail}", $response->status()),
            default => new KohaRejectedException("Koha rejected the request (HTTP {$response->status()}): {$detail}", $response->status()),
        };
    }

    private function errorDetail(Response $response): string
    {
        $json = $response->json();

        if (is_array($json)) {
            $message = $json['error'] ?? $json['message'] ?? null;

            if (isset($json['errors']) && is_array($json['errors'])) {
                $message = trim(($message ?? '').' '.collect($json['errors'])->map(fn ($e) => is_array($e) ? ($e['message'] ?? json_encode($e)) : $e)->implode('; '));
            }

            if ($message) {
                return mb_substr($message, 0, 500);
            }
        }

        return mb_substr(strip_tags($response->body()), 0, 200) ?: 'no details';
    }
}

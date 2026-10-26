<?php

namespace App\Auth\Sso;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Koha Media Manager plugin 2.x "code-v2" hand-off (docs/SSO_PROTOCOL.md in
 * the plugin repository):
 *
 *   Koha ──303──► GET /sso/koha/start?request={64 hex}
 *   MAM  ──303──► fixed Koha return URL (op=continue) with request + new browser state
 *   Koha ──303──► GET /sso/koha?code={64 hex}&state={state}
 *   MAM  ──POST─► fixed Koha exchange URL (X-Media-Client / X-Media-Secret, {"code"})
 *        ◄─200── identity, context, capabilities, state
 *
 * State is generated here, bound to the browser session, single-use and
 * short-lived; it is checked before the exchange (unsolicited callbacks never
 * reach Koha) and again against the exchange response (code substitution).
 */
class CodeExchangeAdapter implements KohaSsoAdapter
{
    private const SESSION_KEY = 'dam.sso.pending';

    private const MAX_PENDING = 5;

    public function __construct(private readonly array $config, private readonly string $instanceId, private readonly array $kohaConfig) {}

    public function start(Request $request): string
    {
        $this->assertConfigured($request);

        $initiation = $request->query('request');

        if (! is_string($initiation) || ! preg_match('/^[0-9a-f]{64}$/', $initiation)) {
            throw SsoException::malformed('Invalid initiation.');
        }

        // 256 random bits, base64url (43 characters).
        $state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $pending = $this->pending($request);
        $pending[$state] = ['expires' => now()->getTimestamp() + (int) $this->config['state_ttl']];

        // Concurrent tabs get distinct states; keep only the newest few.
        uasort($pending, fn ($a, $b) => $a['expires'] <=> $b['expires']);
        $request->session()->put(self::SESSION_KEY, array_slice($pending, -self::MAX_PENDING, null, true));

        return $this->config['return_url'].'?'.http_build_query([
            'class' => $this->config['plugin_class'],
            'method' => 'tool',
            'op' => 'continue',
            'request' => $initiation,
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function authenticate(Request $request): SsoAssertion
    {
        $this->assertConfigured($request);

        $code = $request->query('code');
        $state = $request->query('state');

        if (! is_string($code) || ! preg_match('/^[0-9a-f]{64}$/', $code)
            || ! is_string($state) || ! preg_match('/^[A-Za-z0-9_-]{43,128}$/', $state)) {
            throw SsoException::malformed('Invalid callback parameters.');
        }

        // Verify and consume the browser state BEFORE exchanging the code.
        $pending = $this->pending($request);
        $entry = $pending[$state] ?? null;
        unset($pending[$state]);
        $request->session()->put(self::SESSION_KEY, $pending);

        if (! $entry || $entry['expires'] < now()->getTimestamp()) {
            throw SsoException::stateMismatch();
        }

        $data = $this->exchange($code);
        $this->verify($data, $state);

        return new SsoAssertion(
            kohaInstanceId: $this->instanceId,
            patronId: $data['identity']['patron_id'],
            userid: $data['identity']['userid'],
            biblioId: $data['context']['biblio_id'],
            branchHint: $data['context']['branch'] !== '' ? mb_substr($data['context']['branch'], 0, 20) : null,
            tokenId: $data['correlation_id'],
            kohaCapabilities: array_values($data['capabilities']),
            itemId: $data['context']['item_id'] ?? null,
        );
    }

    public function describe(): array
    {
        return [
            'plugin_contract' => 'Koha Media Manager plugin 2.x (code-v2 authorization-code exchange)',
            'endpoint' => '/sso/koha/start → Koha → /sso/koha',
            'start_url' => url('/sso/koha/start'),
            'return_url' => $this->config['return_url'],
            'exchange_url' => $this->config['exchange_url'],
            'client_id' => $this->config['client_id'] ?: '(not set)',
            'issuer' => $this->instanceId,
            'secret_configured' => $this->secretValid(),
            'insecure_http' => (bool) $this->config['allow_insecure_http'],
            'replay_protection' => 'single-use code (Koha) + single-use browser state (MAM)',
        ];
    }

    private function exchange(string $code): array
    {
        try {
            $response = Http::timeout((int) ($this->kohaConfig['timeout'] ?? 15))
                ->connectTimeout((int) ($this->kohaConfig['connect_timeout'] ?? 5))
                ->withOptions(['verify' => filter_var($this->kohaConfig['verify_tls'] ?? true, FILTER_VALIDATE_BOOL), 'allow_redirects' => false])
                ->withHeaders([
                    'X-Media-Client' => $this->config['client_id'],
                    'X-Media-Secret' => $this->config['client_secret'],
                ])
                ->acceptJson()
                ->asJson()
                ->post($this->config['exchange_url'], ['code' => $code]);
        } catch (ConnectionException $e) {
            Log::warning('Koha SSO exchange unreachable', ['error' => $e->getMessage()]);

            throw SsoException::unavailable('Koha exchange endpoint unreachable.');
        }

        $error = is_array($response->json()) ? ($response->json('error') ?? null) : null;

        if ($response->successful()) {
            return is_array($response->json()) ? $response->json() : throw SsoException::invalidResponse('Exchange response is not JSON.');
        }

        Log::warning('Koha SSO exchange rejected', ['status' => $response->status(), 'error' => is_string($error) ? $error : null]);

        throw match (true) {
            $response->status() === 401, $error === 'invalid_client' => SsoException::misconfigured('Koha rejected the MAM client credentials (check KOHA_SSO_CLIENT_ID / KOHA_SSO_CLIENT_SECRET).'),
            $error === 'https_required' => SsoException::misconfigured('Koha requires HTTPS for the exchange.'),
            $response->status() === 403 => SsoException::identityRejected('Koha denied the staff authorization.'),
            $response->status() === 429 => SsoException::rateLimited(),
            $response->status() === 400 => SsoException::invalidGrant(),
            $response->status() === 404 => SsoException::misconfigured('Koha exchange endpoint not found (plugin not enabled, or Plack/API workers not restarted).'),
            default => SsoException::unavailable("Koha exchange returned HTTP {$response->status()}."),
        };
    }

    /**
     * Strictly validate the exchange response against the published contract.
     */
    private function verify(array $data, string $expectedState): void
    {
        $int = fn ($v) => is_int($v) && $v >= 1;
        $str = fn ($v, int $max = 255) => is_string($v) && $v !== '' && mb_strlen($v) <= $max;

        $checks = [
            'success' => ($data['success'] ?? null) === true,
            'protocol' => ($data['protocol'] ?? null) === 'code-v2',
            'iss' => ($data['iss'] ?? null) === $this->instanceId,
            'aud' => ($data['aud'] ?? null) === $this->config['audience'],
            'identity.provider' => ($data['identity']['provider'] ?? null) === 'koha',
            'identity.instance' => ($data['identity']['instance'] ?? null) === $this->instanceId,
            'identity.patron_id' => $int($data['identity']['patron_id'] ?? null),
            'identity.userid' => $str($data['identity']['userid'] ?? null, 75) && ! preg_match('/[\x00-\x1F]/', $data['identity']['userid']),
            // Null (plugin 1.1+): the plugin dashboard's "Go to MAM", which opens the MAM dashboard; never with an item.
            'context.biblio_id' => $int($data['context']['biblio_id'] ?? null)
                || (($data['context']['biblio_id'] ?? null) === null && ($data['context']['item_id'] ?? null) === null),
            'context.branch' => is_string($data['context']['branch'] ?? null),
            // Optional (plugin 2.5+): absent or null for the record, else an item of that record.
            'context.item_id' => ($data['context']['item_id'] ?? null) === null || $int($data['context']['item_id']),
            'capabilities' => is_array($data['capabilities'] ?? null) && in_array('media.access', $data['capabilities'], true)
                && array_diff($data['capabilities'], array_keys($this->config['capability_map'])) === [],
            'issued_at' => is_int($data['issued_at'] ?? null) && $data['issued_at'] <= now()->getTimestamp() + 300,
            'expires_at' => is_int($data['expires_at'] ?? null) && is_int($data['issued_at'] ?? null) && $data['expires_at'] > $data['issued_at'],
            'correlation_id' => is_string($data['correlation_id'] ?? null) && (bool) preg_match('/^[0-9a-f]{64}$/', $data['correlation_id']),
            'state' => is_string($data['state'] ?? null) && hash_equals($expectedState, $data['state']),
        ];

        $failed = array_keys(array_filter($checks, fn ($ok) => ! $ok));

        if ($failed !== []) {
            // A wrong issuer, audience or state means the response must not be trusted at all.
            throw $failed === ['state']
                ? SsoException::stateMismatch()
                : SsoException::invalidResponse('Exchange response failed validation: '.implode(', ', $failed));
        }
    }

    private function pending(Request $request): array
    {
        $now = now()->getTimestamp();

        return array_filter((array) $request->session()->get(self::SESSION_KEY, []), fn ($e) => is_array($e) && ($e['expires'] ?? 0) >= $now);
    }

    private function assertConfigured(Request $request): void
    {
        foreach (['client_id', 'exchange_url', 'return_url'] as $key) {
            if (blank($this->config[$key] ?? null)) {
                throw SsoException::misconfigured("SSO setting {$key} is missing.");
            }
        }

        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$/', (string) $this->config['client_id']) || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$/', $this->instanceId)) {
            throw SsoException::misconfigured('KOHA_SSO_CLIENT_ID or KOHA_INSTANCE_ID has an invalid format.');
        }

        if (! $this->secretValid()) {
            throw SsoException::misconfigured('KOHA_SSO_CLIENT_SECRET must be 64 lowercase hexadecimal characters.');
        }

        $insecure = (bool) $this->config['allow_insecure_http'];

        foreach (['exchange_url', 'return_url'] as $key) {
            $scheme = parse_url((string) $this->config[$key], PHP_URL_SCHEME);

            if ($scheme !== 'https' && ! ($insecure && $scheme === 'http')) {
                throw SsoException::misconfigured("{$key} must use HTTPS (or enable DAM_ALLOW_INSECURE_HTTP for isolated testing).");
            }
        }

        if (($this->config['require_https'] ?? false) && ! $insecure && ! $request->isSecure()) {
            throw SsoException::insecureTransport();
        }
    }

    private function secretValid(): bool
    {
        return (bool) preg_match('/^[0-9a-f]{64}$/', (string) ($this->config['client_secret'] ?? ''));
    }
}

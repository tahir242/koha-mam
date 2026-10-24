<?php

namespace App\Delivery;

use App\Enums\MediaType;
use App\Models\DigitalAsset;
use App\Models\MediaAccessEvent;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Engagement-based view counting, modelled on how video platforms count views:
 *
 *  - Opening the viewer records an "open" and hands the page a signed,
 *    single-use view token.
 *  - A view is reported by the page once the visitor has watched or read for
 *    the qualifying time; the server checks that this much time has really
 *    passed since the token was issued.
 *  - Repeat views by the same viewer count again only after a pause, up to a
 *    daily cap; bots, staff and unpublished previews never add to the total.
 *
 * Viewers are recognised by a keyed hash of address and browser that lives
 * only in the cache (at most a day); no IP address is ever stored.
 */
class ViewCounter
{
    public const COUNTED = 'counted';

    private const BOT = '/bot|crawl|spider|slurp|preview|scan|monitor|headless|phantom|lighthouse|curl|wget|python|java\/|httpclient|okhttp|go-http|axios|node-fetch|facebookexternalhit|embedly|whatsapp|telegram/i';

    /** Tolerance for clock jitter between the browser timer and the server. */
    private const GRACE_SECONDS = 2;

    public function __construct(private readonly MediaAccess $access) {}

    /** Record that the viewer page was opened. */
    public function open(Request $request, DigitalAsset $asset): void
    {
        if (! config('dam.viewer.record_views')) {
            return;
        }

        $this->event($request, $asset, 'open');
    }

    /** Signed token the page sends back when the visitor has engaged. */
    public function token(DigitalAsset $asset): string
    {
        return Crypt::encryptString(json_encode(['a' => $asset->id, 'n' => Str::random(32), 't' => now()->getTimestamp()]));
    }

    /** Seconds of engagement that make a view of this asset. */
    public function qualifyingSeconds(DigitalAsset $asset): int
    {
        $config = config('dam.viewer.views.qualifying_seconds');
        $seconds = (int) ($config[$asset->media_type->value] ?? $config['document']);

        if (in_array($asset->media_type, [MediaType::Video, MediaType::Audio], true)) {
            $duration = (float) ($asset->currentVersion?->metadata['duration'] ?? 0);

            if ($duration > 0) {
                $seconds = min($seconds, (int) ceil($duration / 2));
            }
        }

        return max(1, $seconds);
    }

    /**
     * Register an engaged view reported by the viewer page.
     *
     * @return array{counted: bool, reason: string, views: int}
     */
    public function engage(Request $request, DigitalAsset $asset, string $token): array
    {
        $result = fn (string $reason) => ['counted' => $reason === self::COUNTED, 'reason' => $reason, 'views' => (int) $asset->fresh()->view_count];

        if (! config('dam.viewer.record_views')) {
            return $result('disabled');
        }

        $claims = $this->claims($token);
        $now = now()->getTimestamp();

        if ($claims === null || $claims['a'] !== $asset->id || $now - $claims['t'] > config('dam.viewer.views.token_ttl_seconds')) {
            return $result('invalid');
        }

        if ($now - $claims['t'] < $this->qualifyingSeconds($asset) - self::GRACE_SECONDS) {
            return $result('too_early');
        }

        // Each page load can produce one view at most.
        if (! Cache::add('views:token:'.$claims['n'], 1, config('dam.viewer.views.token_ttl_seconds'))) {
            return $result('duplicate');
        }

        $reason = $this->reason($request, $asset);
        $counted = $reason === self::COUNTED;

        if ($counted) {
            // Query-builder increment: no updated_at bump, no activity log entry.
            DigitalAsset::query()->whereKey($asset->id)->toBase()->increment('view_count', 1, ['last_viewed_at' => now()]);
        }

        $this->event($request, $asset, 'view', $counted, $counted ? null : $reason);

        return $result($reason);
    }

    /** Why a view does or does not count; "counted" when it does. */
    private function reason(Request $request, DigitalAsset $asset): string
    {
        if (! $asset->isPublished()) {
            return 'preview';
        }

        if (preg_match(self::BOT, (string) $request->userAgent()) || trim((string) $request->userAgent()) === '') {
            return 'bot';
        }

        if ($this->access->isStaff($request->user()) && ! config('dam.viewer.views.count_staff')) {
            return 'staff';
        }

        $viewer = hash_hmac('sha256', $request->ip().'|'.$request->userAgent(), config('app.key'));
        $key = "views:viewer:{$asset->id}:{$viewer}";

        return Cache::lock($key.':lock', 5)->block(5, function () use ($key) {
            $now = now()->getTimestamp();
            $today = now()->toDateString();
            $state = Cache::get($key, ['last' => 0, 'day' => $today, 'count' => 0]);

            if ($state['day'] !== $today) {
                $state = ['last' => 0, 'day' => $today, 'count' => 0];
            }

            if ($now - $state['last'] < config('dam.viewer.views.repeat_after_minutes') * 60) {
                return 'repeat';
            }

            if ($state['count'] >= config('dam.viewer.views.max_per_viewer_per_day')) {
                return 'daily_cap';
            }

            Cache::put($key, ['last' => $now, 'day' => $today, 'count' => $state['count'] + 1], now()->endOfDay());

            return self::COUNTED;
        });
    }

    /** @return array{a: int, n: string, t: int}|null */
    private function claims(string $token): ?array
    {
        try {
            $claims = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            return null;
        }

        return is_array($claims) && is_int($claims['a'] ?? null) && is_string($claims['n'] ?? null) && is_int($claims['t'] ?? null) ? $claims : null;
    }

    private function event(Request $request, DigitalAsset $asset, string $event, bool $counted = false, ?string $reason = null): void
    {
        MediaAccessEvent::create([
            'digital_asset_id' => $asset->id,
            'event' => $event,
            // Keyed hash, rotated daily: counts unique visitors without storing IPs.
            'visitor_hash' => hash_hmac('sha256', $request->ip().'|'.now()->toDateString(), config('app.key')),
            'referrer_host' => Str::limit((string) parse_url((string) $request->headers->get('referer'), PHP_URL_HOST), 250, ''),
            'user_agent_family' => $this->agentFamily((string) $request->userAgent()),
            'is_staff' => $this->access->isStaff($request->user()),
            'counted' => $counted,
            'reason' => $reason,
        ]);
    }

    private function agentFamily(string $agent): string
    {
        return match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            (bool) preg_match('/bot|crawl|spider/i', $agent) => 'Bot',
            default => 'Other',
        };
    }
}

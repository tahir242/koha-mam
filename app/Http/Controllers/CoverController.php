<?php

namespace App\Http\Controllers;

use App\Delivery\MediaAccess;
use App\Models\DigitalAsset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Proxies Koha local cover images (opac-image.pl) through this origin and
 * caches them, so pages keep a strict same-origin CSP and the public viewer
 * does not hit Koha on every page view. Only records with an asset the
 * requester may see are served (no open proxy).
 */
class CoverController extends Controller
{
    public function __invoke(Request $request, int $biblio, MediaAccess $access): Response
    {
        $visible = $access->isStaff($request->user())
            || DigitalAsset::query()->forBiblio($biblio)->published()->where('access_level', 'public')->exists();

        abort_unless($visible && config('dam.koha.cover.koha_local') && config('dam.koha.opac_url'), 404);

        $cover = Cache::remember('koha:cover:'.config('dam.koha.instance_id').':'.$biblio, config('dam.koha.cover.cache_ttl'), function () use ($biblio) {
            try {
                $response = Http::timeout(5)->connectTimeout(3)
                    ->withOptions(['verify' => filter_var(config('dam.koha.verify_tls'), FILTER_VALIDATE_BOOL)])
                    ->get(config('dam.koha.opac_url').'/cgi-bin/koha/opac-image.pl', ['thumbnail' => 1, 'biblionumber' => $biblio]);
            } catch (Throwable) {
                return ['missing' => true];
            }

            $type = strtolower(strtok((string) $response->header('Content-Type'), ';'));

            // Koha returns a 1x1 GIF placeholder when no local cover exists.
            if (! $response->successful() || ! in_array($type, ['image/jpeg', 'image/png', 'image/webp'], true) || strlen($response->body()) > 2_000_000) {
                return ['missing' => true];
            }

            return ['type' => $type, 'data' => base64_encode($response->body())];
        });

        // No cover in Koha: an empty success, so the workspace's background image
        // simply stays blank instead of logging a 404 in the browser console.
        if (! empty($cover['missing'])) {
            return response()->noContent(204, ['Cache-Control' => 'private, max-age=3600']);
        }

        return response(base64_decode($cover['data']), 200, [
            'Content-Type' => $cover['type'],
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

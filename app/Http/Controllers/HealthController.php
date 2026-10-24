<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Health\ResultStores\ResultStore;

/**
 * Machine-readable health results (written every minute by the scheduler).
 * Requires `Authorization: Bearer <DAM_HEALTH_TOKEN>`; disabled when unset.
 */
class HealthController extends Controller
{
    public function __invoke(Request $request, ResultStore $store): JsonResponse
    {
        $token = (string) config('dam.health.token');

        abort_if($token === '' || ! hash_equals($token, (string) $request->bearerToken()), 404);

        $results = $store->latestResults();

        return response()->json([
            'finished_at' => $results?->finishedAt?->format(DATE_ATOM),
            'checks' => $results?->storedCheckResults->map(fn ($r) => [
                'name' => $r->name,
                'label' => $r->label,
                'status' => $r->status,
                'summary' => $r->shortSummary,
            ])->values() ?? [],
        ], $results && $results->containsFailingCheck() ? 503 : 200);
    }
}

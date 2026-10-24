<?php

namespace App\Http\Middleware;

use App\Enums\Capability;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces an absolute session lifetime (staff must re-enter through Koha)
 * and re-checks that the identity is still active on every request.
 */
class EnsureStaffSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $authenticatedAt = (int) $request->session()->get('dam.authenticated_at', 0);
        $lifetime = (int) config('dam.sso.absolute_lifetime_minutes') * 60;

        $expired = $authenticatedAt === 0 || (time() - $authenticatedAt) > $lifetime;

        // Apply the Koha capability ceiling stored at sign-in. A session without
        // one was not created by the Koha hand-off and is not trusted.
        $session = $request->session();
        $ceilingMissing = ! $session->has('dam.koha_capabilities');
        $user?->setKohaCapabilities($session->get('dam.koha_capabilities'));

        if (! $user || $expired || $ceilingMissing || ! $user->hasCapability(Capability::ViewDashboard)) {
            if ($user) {
                activity('auth')->causedBy($user)->event('session_ended')
                    ->withProperties(['reason' => $expired ? 'absolute_lifetime' : 'access_revoked'])
                    ->log('Staff session ended');
            }

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
                abort(401, 'Your session has ended. Please sign in again from Koha.');
            }

            return redirect()->route('login')->with('status', $expired && $user ? 'Your session has ended. Please open the MAM again from Koha.' : null);
        }

        return $next($request);
    }
}

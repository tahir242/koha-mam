<?php

namespace App\Http\Controllers\Auth;

use App\Auth\Sso\KohaSsoAdapter;
use App\Auth\Sso\SsoException;
use App\Auth\StaffLogin;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class KohaSsoController extends Controller
{
    /**
     * code-v2 step 1: Koha sends the browser here with an opaque initiation.
     * The MAM binds a fresh state to this browser session and sends the
     * browser back to the fixed Koha return URL.
     */
    public function start(Request $request, KohaSsoAdapter $adapter): Response
    {
        try {
            $target = $adapter->start($request);
        } catch (SsoException $e) {
            return $this->failure($request, $e);
        }

        return $this->noStore(new RedirectResponse($target, 303));
    }

    /**
     * Callback from Koha (code-v2: code + state; legacy: signed redirect).
     * On success the browser is redirected to a clean URL, so credentials
     * never stay in the address bar, and no Referer leaks.
     */
    public function __invoke(Request $request, KohaSsoAdapter $adapter, StaffLogin $login): Response
    {
        try {
            $assertion = $adapter->authenticate($request);
            $login->login($request, $assertion);
        } catch (SsoException $e) {
            return $this->failure($request, $e);
        }

        $target = match (true) {
            $assertion->biblioId && $assertion->itemId => route('biblios.show', ['biblio' => $assertion->biblioId, 'item' => $assertion->itemId]),
            (bool) $assertion->biblioId => route('biblios.show', $assertion->biblioId),
            default => route('dashboard'),
        };

        return $this->noStore(new RedirectResponse($target, 303));
    }

    /** Optional protocol advertisement used by the plugin's diagnostics. */
    public function wellKnown(): JsonResponse
    {
        return response()->json(['protocol' => 'code-v2', 'audience' => config('dam.sso.audience')])
            ->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request): RedirectResponse
    {
        if ($user = $request->user()) {
            activity('auth')->causedBy($user)->event('logout')->log('Staff signed out');
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function failure(Request $request, SsoException $e): Response
    {
        activity('auth')->causedByAnonymous()->event('sso_failed')
            ->withProperties(['reason' => $e->reason, 'detail' => $e->getMessage(), 'ip' => $request->ip()])
            ->log('Koha SSO rejected: '.$e->reason);

        if (Auth::check()) {
            Auth::logout();
            $request->session()->invalidate();
        }

        $status = match ($e->reason) {
            'misconfigured', 'unavailable', 'invalid_response' => 503,
            'rate_limited' => 429,
            default => 403,
        };

        return $this->noStore(response()->view('auth.sso-error', [
            'message' => $e->publicMessage(),
            'kohaUrl' => config('dam.koha.staff_url'),
        ], $status));
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}

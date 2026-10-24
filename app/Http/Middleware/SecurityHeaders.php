<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Content Security Policy (nonce-based) and standard hardening headers.
 * The public viewer runs no Alpine/Livewire, so it gets a stricter policy
 * without 'unsafe-eval'. Responses that already define a CSP (protected
 * media) keep their own.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        Vite::useCspNonce();

        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', $headers->get('Referrer-Policy', 'strict-origin-when-cross-origin'));
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        // Browsers ignore (and warn about) COOP on plain-HTTP origins.
        if ($request->isSecure()) {
            $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        }

        if (! $headers->has('X-Frame-Options')) {
            $headers->set('X-Frame-Options', 'DENY');
        }

        if ($request->isSecure() && app()->isProduction()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $this->policy($request));
        }

        return $response;
    }

    private function policy(Request $request): string
    {
        $nonce = Vite::cspNonce();
        $public = $request->routeIs('viewer.*');
        $hot = Vite::isRunningHot() ? ' '.$this->hotOrigin() : '';

        // Staff pages need 'unsafe-eval' for Alpine expressions; the public
        // viewer only needs WebAssembly (PDF.js image decoders).
        $script = "'self' 'nonce-{$nonce}'".($public ? " 'wasm-unsafe-eval'" : " 'unsafe-eval'").$hot;
        $connect = "'self'".($hot ? $hot.' '.str_replace('http', 'ws', trim($hot)) : '');

        return implode('; ', [
            "default-src 'self'",
            "script-src {$script}",
            "style-src 'self' 'unsafe-inline'{$hot}",
            "img-src 'self' data: blob:",
            "font-src 'self' data:{$hot}",
            "media-src 'self' blob:",
            "worker-src 'self' blob:",
            "connect-src {$connect}",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    private function hotOrigin(): string
    {
        $url = trim((string) @file_get_contents(public_path('hot')));

        return $url !== '' ? rtrim($url, '/') : '';
    }
}

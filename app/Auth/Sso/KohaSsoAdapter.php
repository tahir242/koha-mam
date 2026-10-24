<?php

namespace App\Auth\Sso;

use Illuminate\Http\Request;

/**
 * Boundary between the MAM and the Koha Media Manager plugin's hand-off
 * protocol. Implementations must be strict: anything that cannot be fully
 * verified throws an SsoException.
 */
interface KohaSsoAdapter
{
    /**
     * Handle GET /sso/koha/start and return the URL to redirect the browser
     * to, or throw when the protocol has no start step.
     *
     * @throws SsoException
     */
    public function start(Request $request): string;

    /**
     * Handle the callback GET /sso/koha.
     *
     * @throws SsoException
     */
    public function authenticate(Request $request): SsoAssertion;

    /** Human-readable protocol description for diagnostics. */
    public function describe(): array;
}

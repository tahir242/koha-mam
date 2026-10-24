<?php

namespace App\Auth\Sso;

use RuntimeException;

class SsoException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function misconfigured(string $detail): self
    {
        return new self('misconfigured', $detail);
    }

    public static function malformed(string $detail): self
    {
        return new self('malformed', $detail);
    }

    public static function insecureTransport(): self
    {
        return new self('insecure_transport', 'Single sign-on requires HTTPS.');
    }

    public static function identityRejected(string $detail): self
    {
        return new self('identity_rejected', $detail);
    }

    public static function stateMismatch(): self
    {
        return new self('state_mismatch', 'The sign-in callback does not belong to a sign-in started in this browser.');
    }

    public static function invalidGrant(string $detail = 'Koha rejected the authorization code.'): self
    {
        return new self('invalid_grant', $detail);
    }

    public static function unavailable(string $detail): self
    {
        return new self('unavailable', $detail);
    }

    public static function rateLimited(): self
    {
        return new self('rate_limited', 'Koha rate-limited the sign-in exchange.');
    }

    public static function invalidResponse(string $detail): self
    {
        return new self('invalid_response', $detail);
    }

    /** Message safe to show to the end user. */
    public function publicMessage(): string
    {
        return match ($this->reason) {
            'state_mismatch' => 'This sign-in was not started from this browser, or it took too long. Please return to Koha and click "Attach Media" again.',
            'invalid_grant' => 'The sign-in from Koha expired or was already used. Please return to Koha and click "Attach Media" again.',
            'unavailable' => 'Koha could not be reached to complete the sign-in. Please try again in a moment.',
            'rate_limited' => 'Too many sign-in attempts. Please wait a minute and try again from Koha.',
            'invalid_response' => 'Koha returned an unexpected sign-in response. Please contact the library systems administrator.',
            'misconfigured' => 'Single sign-on is not configured correctly. Please contact the library systems administrator.',
            'insecure_transport' => 'Single sign-on is only available over a secure (HTTPS) connection.',
            'identity_rejected' => 'Your Koha account is not permitted to use the Media Asset Management (MAM) system.',
            default => 'The sign-in link from Koha could not be verified. Please start again from Koha.',
        };
    }
}

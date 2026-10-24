<?php

namespace App\Auth;

use App\Auth\Sso\SsoAssertion;
use App\Auth\Sso\SsoException;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Turns a verified SSO assertion into a local session. No password is
 * created or stored; the identity record only anchors ownership and roles.
 */
class StaffLogin
{
    /**
     * @throws SsoException
     */
    public function login(Request $request, SsoAssertion $assertion): User
    {
        $user = User::query()->firstOrNew([
            'koha_instance_id' => $assertion->kohaInstanceId,
            'koha_patron_id' => $assertion->patronId,
        ]);

        if (! $user->exists) {
            $user->role = config('dam.authorization.default_role');
            $user->is_active = true;
        }

        $user->koha_userid = $assertion->userid;
        $user->display_name ??= $assertion->userid;
        $user->branch_code = $assertion->branchHint ?? $user->branch_code;

        if (in_array($assertion->userid, config('dam.authorization.bootstrap_admins'), true)) {
            $user->role = 'administrator';
            $user->is_active = true;
        }

        if (! $user->is_active || $user->role === 'suspended') {
            activity('auth')->causedByAnonymous()->withProperties(['koha_userid' => $assertion->userid, 'reason' => 'suspended'])->event('sso_denied')->log('SSO login denied: identity suspended in MAM');

            throw SsoException::identityRejected('Identity is suspended in the MAM.');
        }

        $user->last_authenticated_at = now();
        $user->save();

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->regenerateToken();
        $request->session()->put('dam.authenticated_at', now()->getTimestamp());
        $request->session()->put('dam.sso_token_id', substr($assertion->tokenId, 0, 16));
        // Koha capabilities cap the MAM role for this session (see EnsureStaffSession).
        $request->session()->put('dam.koha_capabilities', $assertion->kohaCapabilities);
        $user->setKohaCapabilities($assertion->kohaCapabilities);

        activity('auth')->causedBy($user)->performedOn($user)->event('sso_login')
            ->withProperties([
                'biblio_id' => $assertion->biblioId,
                'item_id' => $assertion->itemId,
                'ip' => $request->ip(),
                'koha_correlation_id' => $assertion->tokenId,
                'koha_capabilities' => $assertion->kohaCapabilities === null ? null : implode(',', $assertion->kohaCapabilities),
                'transport' => $request->isSecure() ? 'https' : 'http',
            ])
            ->log('Staff signed in through Koha SSO');

        return $user;
    }
}

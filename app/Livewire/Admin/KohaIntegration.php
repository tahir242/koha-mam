<?php

namespace App\Livewire\Admin;

use App\Auth\Sso\KohaSsoAdapter;
use App\Koha\KohaClient;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Koha integration')]
class KohaIntegration extends Component
{
    public ?int $probeBiblio = null;

    public ?array $result = null;

    public function boot(): void
    {
        $this->authorize('media.manage_settings');
    }

    public function test(KohaClient $client): void
    {
        $this->validate(['probeBiblio' => ['nullable', 'integer', 'min:1']]);
        $this->result = $client->ping($this->probeBiblio);
        Cache::put('dam:koha-status', $this->result, 300);

        activity('settings')->causedBy(auth()->user())->event('koha_diagnostics')
            ->withProperties(['ok' => $this->result['ok'], 'probe' => $this->probeBiblio])
            ->log('Koha connectivity test');
    }

    public function render(KohaClient $client, KohaSsoAdapter $sso)
    {
        $auth = config('dam.koha.auth');

        return view('livewire.admin.koha-integration', [
            'configured' => $client->isConfigured(),
            'settings' => [
                'Instance identifier' => config('dam.koha.instance_id'),
                'REST API base URL' => config('dam.koha.staff_url') ?: 'Not set',
                'Staff client URL' => config('dam.koha.staff_url') ?: 'Not set',
                'OPAC URL (covers)' => config('dam.koha.opac_url') ?: 'Not set',
                'API authentication' => $auth['method'] === 'basic' ? 'HTTP Basic (RESTBasicAuth)' : 'OAuth2 client credentials (RESTOAuth2ClientCredentials)',
                'API credentials' => ($auth['method'] === 'basic' ? filled($auth['username']) && filled($auth['password']) : filled($auth['client_id']) && filled($auth['client_secret'])) ? 'Configured (hidden)' : 'Missing',
                'TLS verification' => filter_var(config('dam.koha.verify_tls'), FILTER_VALIDATE_BOOL) ? 'Enabled' : 'Disabled',
                'Metadata cache TTL' => config('dam.koha.metadata_cache_ttl').' s',
                'MAM public URL (856 $u)' => config('dam.public_url'),
            ],
            'sso' => $sso->describe(),
            'ssoEndpoint' => route('sso.koha'),
        ]);
    }
}

<?php

namespace App\Livewire\Admin;

use App\Settings\Settings;
use Flux\Flux;
use Illuminate\Support\Number;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Non-secret runtime settings. Credentials, URLs and security-critical
 * values stay in the environment; changes here are audited.
 */
#[Title('Settings')]
class SettingsPage extends Component
{
    public bool $watermark_enabled = true;

    public ?string $watermark_text = null;

    public bool $default_requires_approval = true;

    public int $metadata_cache_ttl = 3600;

    public string $duplicate_policy = 'block';

    public function boot(): void
    {
        $this->authorize('media.manage_settings');
    }

    public function mount(Settings $settings): void
    {
        $this->watermark_enabled = (bool) $settings->get('viewer.watermark_enabled', config('dam.viewer.watermark.enabled'));
        $this->watermark_text = $settings->get('viewer.watermark_text', config('dam.viewer.watermark.text'));
        $this->default_requires_approval = (bool) config('dam.publishing.default_requires_approval');
        $this->metadata_cache_ttl = (int) config('dam.koha.metadata_cache_ttl');
        $this->duplicate_policy = config('dam.upload.duplicate_policy');
    }

    public function save(Settings $settings): void
    {
        $this->validate([
            'watermark_enabled' => Settings::EDITABLE['viewer.watermark_enabled'],
            'watermark_text' => Settings::EDITABLE['viewer.watermark_text'],
            'default_requires_approval' => Settings::EDITABLE['publishing.default_requires_approval'],
            'metadata_cache_ttl' => Settings::EDITABLE['koha.metadata_cache_ttl'],
            'duplicate_policy' => Settings::EDITABLE['upload.duplicate_policy'],
        ]);

        $user = auth()->user();
        $settings->set('viewer.watermark_enabled', $this->watermark_enabled, $user);
        $settings->set('viewer.watermark_text', $this->watermark_text, $user);
        $settings->set('publishing.default_requires_approval', $this->default_requires_approval, $user);
        $settings->set('koha.metadata_cache_ttl', $this->metadata_cache_ttl, $user);
        $settings->set('upload.duplicate_policy', $this->duplicate_policy, $user);

        Flux::toast(variant: 'success', text: 'Settings saved.');
    }

    public function render()
    {
        return view('livewire.admin.settings-page', [
            'readOnly' => [
                'Session idle lifetime' => config('session.lifetime').' minutes',
                'Absolute session lifetime' => config('dam.sso.absolute_lifetime_minutes').' minutes',
                'Secure session cookie' => config('session.secure') ? 'Yes' : 'No (enable SESSION_SECURE_COOKIE in production)',
                'Signed media URL lifetime' => config('dam.delivery.signed_url_ttl').' seconds',
                'Delivery driver' => config('dam.delivery.driver'),
                'Block cross-site media requests' => config('dam.delivery.block_cross_site') ? 'Yes' : 'No',
                'Audit retention' => config('dam.audit.retention_days').' days',
                'View-event retention' => config('dam.audit.access_event_retention_days').' days',
                'Malware scanner' => config('dam.scanning.driver').(config('dam.scanning.required') ? ' (required)' : ''),
                'Maximum upload size' => Number::fileSize(config('dam.upload.max_bytes')),
                'Four-eyes approval' => config('dam.authorization.enforce_four_eyes') ? 'Enforced' : 'Not enforced',
            ],
        ]);
    }
}

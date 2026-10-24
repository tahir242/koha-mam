<div class="fade-in">
    <x-page-header eyebrow="Administration" title="Koha integration" description="Connection settings come from the server environment (.env). Secrets are never displayed or editable here." />

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="card">
            <div class="border-b border-zinc-100 px-6 py-4"><h2 class="font-semibold text-zinc-900">REST API</h2></div>
            <dl class="divide-y divide-zinc-100 text-sm">
                @foreach ($settings as $label => $value)
                    <div class="flex flex-col gap-1 px-6 py-3 sm:flex-row sm:justify-between">
                        <dt class="text-zinc-500">{{ $label }}</dt>
                        <dd class="break-all font-medium text-zinc-800 sm:text-right">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
            <form wire:submit="test" class="flex flex-col gap-3 border-t border-zinc-100 p-6 sm:flex-row sm:items-end">
                <flux:input wire:model="probeBiblio" type="number" min="1" label="Probe record (optional)" description="Also fetch this biblio to verify read permission." class="flex-1" />
                <flux:button type="submit" variant="primary" icon="signal">Test connection</flux:button>
            </form>
            @if ($result)
                <div class="px-6 pb-6">
                    <x-notice :variant="$result['ok'] ? 'success' : 'danger'" :icon="$result['ok'] ? 'check-circle' : 'x-circle'" :heading="$result['ok'] ? 'Connected' : 'Connection failed'">
                        {{ $result['message'] }} @if ($result['latency_ms']) ({{ $result['latency_ms'] }} ms) @endif
                    </x-notice>
                </div>
            @endif
        </section>

        <section class="card">
            <div class="border-b border-zinc-100 px-6 py-4"><h2 class="font-semibold text-zinc-900">Staff single sign-on</h2></div>
            <dl class="divide-y divide-zinc-100 text-sm">
                <div class="flex justify-between gap-4 px-6 py-3"><dt class="text-zinc-500">Protocol</dt><dd class="text-right font-medium">{{ $sso['plugin_contract'] }}</dd></div>
                <div class="flex justify-between gap-4 px-6 py-3"><dt class="text-zinc-500">Plugin "Application base URL"</dt><dd class="break-all text-right font-mono text-xs">{{ url('/') }}</dd></div>
                <div class="flex justify-between gap-4 px-6 py-3"><dt class="text-zinc-500">Koha return URL</dt><dd class="break-all text-right font-mono text-xs">{{ $sso['return_url'] }}</dd></div>
                <div class="flex justify-between gap-4 px-6 py-3"><dt class="text-zinc-500">Koha exchange URL</dt><dd class="break-all text-right font-mono text-xs">{{ $sso['exchange_url'] }}</dd></div>
                <div class="flex justify-between gap-4 px-6 py-3"><dt class="text-zinc-500">Client ID / issuer</dt><dd class="text-right font-mono text-xs">{{ $sso['client_id'] }} / {{ $sso['issuer'] }}</dd></div>
                <div class="flex justify-between gap-4 px-6 py-3"><dt class="text-zinc-500">Client secret</dt><dd @class(['font-medium', 'text-emerald-700' => $sso['secret_configured'], 'text-red-700' => ! $sso['secret_configured']])>{{ $sso['secret_configured'] ? 'Configured (hidden)' : 'Missing or not 64 hex characters' }}</dd></div>
                <div class="flex justify-between gap-4 px-6 py-3"><dt class="text-zinc-500">Replay protection</dt><dd class="text-right font-medium">{{ $sso['replay_protection'] }}</dd></div>
            </dl>
            <div class="p-6">
                @if (config('dam.sso.allow_insecure_http'))
                    <x-notice variant="danger" heading="Test-only HTTP mode is enabled">
                        DAM_ALLOW_INSECURE_HTTP=true: sign-in codes, client secrets and sessions may travel unencrypted. Use only on an isolated test network and disable it before production.
                    </x-notice>
                @else
                    <x-notice icon="information-circle" heading="Koha capabilities cap MAM roles">
                        The plugin returns media.access / media.upload / media.manage / media.admin. A staff member never gets more in the MAM than Koha allows, whatever their MAM role. See the Developer Guide (docs/DEVELOPER_GUIDE.md, section 4).
                    </x-notice>
                @endif
            </div>
        </section>
    </div>
</div>

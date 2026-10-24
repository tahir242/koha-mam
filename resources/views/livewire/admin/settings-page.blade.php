<div class="fade-in">
    <x-page-header eyebrow="Administration" title="Settings" description="Operational preferences. Every change is recorded in the audit log." />

    <div class="grid gap-6 xl:grid-cols-5">
        <form wire:submit="save" class="card space-y-6 p-6 xl:col-span-3">
            <div>
                <h2 class="font-semibold text-zinc-900">Public viewer</h2>
                <div class="mt-4 space-y-4">
                    <flux:switch wire:model="watermark_enabled" label="Show a visible watermark over viewed resources" description="A deterrent against casual redistribution. It cannot prevent screen capture." />
                    <flux:input wire:model="watermark_text" label="Watermark text" maxlength="120" />
                </div>
            </div>
            <flux:separator />
            <div>
                <h2 class="font-semibold text-zinc-900">Publishing</h2>
                <div class="mt-4">
                    <flux:switch wire:model="default_requires_approval" label="Require approval by default" description="Applies when no publishing policy matches." />
                </div>
            </div>
            <flux:separator />
            <div>
                <h2 class="font-semibold text-zinc-900">Koha metadata and uploads</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="metadata_cache_ttl" type="number" min="60" label="Metadata cache lifetime (seconds)" />
                    <flux:select wire:model="duplicate_policy" label="Identical file on the same record">
                        <flux:select.option value="block">Block the upload</flux:select.option>
                        <flux:select.option value="warn">Allow with a warning</flux:select.option>
                    </flux:select>
                </div>
            </div>
            <div class="flex justify-end"><flux:button type="submit" variant="primary">Save settings</flux:button></div>
        </form>

        <section class="card xl:col-span-2">
            <div class="border-b border-zinc-100 px-6 py-4">
                <h2 class="font-semibold text-zinc-900">Security &amp; delivery</h2>
                <p class="text-xs text-zinc-500">Managed through the server environment.</p>
            </div>
            <dl class="divide-y divide-zinc-100 text-sm">
                @foreach ($readOnly as $label => $value)
                    <div class="flex justify-between gap-4 px-6 py-3"><dt class="text-zinc-500">{{ $label }}</dt><dd class="text-right font-medium text-zinc-800">{{ $value }}</dd></div>
                @endforeach
            </dl>
        </section>
    </div>
</div>

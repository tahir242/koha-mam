<div class="fade-in">
    <x-page-header eyebrow="Administration" title="Storage backends" description="Originals and derivatives are always private. NAS and shared drives must be mounted and secured by the operating system; S3/MinIO credentials come from named profiles in the environment.">
        <x-slot:actions>
            <flux:button variant="primary" icon="plus" wire:click="create">Add backend</flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-5 lg:grid-cols-2">
        @foreach ($backends as $row)
            @php $b = $row['model']; @endphp
            <article class="card p-6" wire:key="b-{{ $b->id }}">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <span class="grid size-11 place-items-center rounded-xl bg-brand-100 text-brand">
                            <flux:icon :name="$b->driver === 's3' ? 'cloud' : 'server'" variant="outline" class="size-5" />
                        </span>
                        <div>
                            <h2 class="font-semibold text-zinc-900">{{ $b->name }}</h2>
                            <p class="text-xs text-zinc-500">{{ $drivers[$b->driver] ?? $b->driver }} · <span class="font-mono">{{ $b->key }}</span></p>
                        </div>
                    </div>
                    <div class="flex flex-col items-end gap-1">
                        @if ($b->is_default)<span class="rounded-full bg-brand px-2.5 py-0.5 text-xs font-medium text-white">Default</span>@endif
                        @unless ($b->is_active)<span class="rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-medium text-zinc-600">Inactive</span>@endunless
                    </div>
                </div>

                <dl class="mt-5 grid grid-cols-2 gap-4 text-sm">
                    <div class="col-span-2"><dt class="text-xs text-zinc-500">{{ $b->driver === 's3' ? 'Bucket' : 'Root path' }}</dt><dd class="break-all font-mono text-xs text-zinc-800">{{ $b->driver === 's3' ? ($b->settings['bucket'] ?? '').(isset($b->settings['prefix']) ? '/'.$b->settings['prefix'] : '').(isset($b->settings['endpoint']) ? ' @ '.$b->settings['endpoint'] : '') : $b->root() }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">Used by MAM</dt><dd class="font-medium">{{ \Illuminate\Support\Number::fileSize($row['used'], 1) }} <span class="text-xs font-normal text-zinc-500">({{ number_format($row['files']) }} files)</span></dd></div>
                    <div><dt class="text-xs text-zinc-500">Capacity</dt><dd class="font-medium">{{ $b->capacity_bytes ? \Illuminate\Support\Number::fileSize($b->capacity_bytes) : '—' }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">Media types</dt><dd class="font-medium">{{ $b->allowed_media_types ? implode(', ', $b->allowed_media_types) : 'All' }}</dd></div>
                    <div>
                        <dt class="text-xs text-zinc-500">Health</dt>
                        <dd @class(['font-medium', 'text-emerald-700' => $b->health_status === 'healthy', 'text-red-700' => $b->health_status === 'failing', 'text-zinc-500' => $b->health_status === 'unknown'])>
                            {{ ucfirst($b->health_status) }}
                            @if ($b->health_checked_at)<span class="block text-xs font-normal text-zinc-500">{{ $b->health_checked_at->diffForHumans() }}</span>@endif
                        </dd>
                    </div>
                </dl>
                @if ($b->health_status === 'failing')
                    <p class="mt-3 rounded-lg bg-red-50 p-3 text-xs text-red-700">{{ $b->health_message }}</p>
                @endif

                <div class="mt-5 flex flex-wrap gap-2 border-t border-zinc-100 pt-4">
                    <flux:button size="sm" icon="pencil-square" wire:click="edit({{ $b->id }})">Edit</flux:button>
                    <flux:button size="sm" icon="heart" wire:click="check({{ $b->id }})">Check health</flux:button>
                    @unless ($b->is_default)
                        <flux:button size="sm" variant="ghost" wire:click="makeDefault({{ $b->id }})" wire:confirm="Make {{ $b->name }} the default for new uploads? Existing files are not moved.">Make default</flux:button>
                    @endunless
                </div>
            </article>
        @endforeach
    </div>

    <flux:modal name="backend" class="md:w-[36rem]">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? 'Edit storage backend' : 'Add storage backend' }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="name" label="Name" required />
                <flux:input wire:model="key" label="Key" description="Stable identifier, used in X-Accel paths." required :disabled="(bool) $editingId" />
            </div>
            <flux:select wire:model.live="driver" label="Type" :disabled="(bool) $editingId">
                @foreach ($drivers as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($driver === 's3')
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="bucket" label="Bucket (private)" required />
                    <flux:input wire:model="prefix" label="Key prefix" />
                    <flux:input wire:model="endpoint" label="Endpoint" placeholder="https://minio.internal:9000" class="sm:col-span-2" />
                    <flux:input wire:model="region" label="Region" />
                    <flux:select wire:model="credentials_profile" label="Credentials profile" description="Defined in .env (DAM_S3_*)">
                        @foreach ($profiles as $p)<flux:select.option value="{{ $p }}">{{ $p }}</flux:select.option>@endforeach
                    </flux:select>
                </div>
                <flux:checkbox wire:model="use_path_style_endpoint" label="Path-style endpoint (required for most MinIO deployments)" />
            @else
                <flux:input wire:model="root" label="Root path" placeholder="/srv/dam/storage" description="Absolute path outside the web root. For NAS/shared drives, the mount point." required />
            @endif

            <flux:checkbox.group wire:model="allowed_media_types" label="Restrict to media types (none = all)">
                <div class="flex flex-wrap gap-4">
                    @foreach ($types as $t)<flux:checkbox value="{{ $t->value }}" label="{{ $t->label() }}" />@endforeach
                </div>
            </flux:checkbox.group>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="capacity_gb" type="number" min="1" label="Capacity (GB, optional)" />
                <div class="pt-7"><flux:checkbox wire:model="is_active" label="Active" /></div>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Save &amp; verify</flux:button>
            </div>
        </form>
    </flux:modal>
</div>

<div class="fade-in">
    <x-page-header eyebrow="Administration" title="Publishing policies" description="Decide who may publish directly and what needs review. Sensitive resources and roles without the publish capability always require approval.">
        <x-slot:actions><flux:button variant="primary" icon="plus" wire:click="create">Add policy</flux:button></x-slot:actions>
    </x-page-header>

    <div class="card mb-6 flex items-start gap-4 p-5">
        <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-100 text-brand"><flux:icon name="scale" variant="outline" class="size-5" /></span>
        <div class="text-sm">
            <p class="font-medium text-zinc-900">How a decision is made</p>
            <ol class="mt-1 list-decimal space-y-0.5 pl-5 text-zinc-600">
                <li>Sensitive resources → approval required.</li>
                <li>Staff without the “publish” capability → approval required.</li>
                <li>Per-resource override, when set by an authorised user.</li>
                <li>The highest-priority matching active policy below (most specific wins ties; on a full tie the stricter rule wins).</li>
                <li>Otherwise the default: <strong>{{ $default ? 'approval required' : 'direct publication allowed' }}</strong> (Settings).</li>
            </ol>
        </div>
    </div>

    <div class="card overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Policy</th><th>Applies to</th><th>Outcome</th><th>Priority</th><th></th></tr></thead>
            <tbody>
                @forelse ($policies as $p)
                    <tr wire:key="pol-{{ $p->id }}" @class(['opacity-50' => ! $p->is_active])>
                        <td><p class="font-medium text-zinc-900">{{ $p->name }}</p>@if ($p->description)<p class="text-xs text-zinc-500">{{ $p->description }}</p>@endif</td>
                        <td class="text-xs text-zinc-600">
                            Role: {{ $p->role ? ($roles[$p->role] ?? $p->role) : 'any' }}<br>
                            Media: {{ $p->media_type ?? 'any' }} · Access: {{ $p->access_level ?? 'any' }}
                            @if ($p->applies_to_sensitive !== null) · {{ $p->applies_to_sensitive ? 'sensitive only' : 'non-sensitive only' }} @endif
                        </td>
                        <td>
                            <span @class(['rounded-full px-2 py-0.5 text-xs font-medium', 'bg-amber-50 text-amber-800' => $p->requires_approval, 'bg-emerald-50 text-emerald-700' => ! $p->requires_approval])>{{ $p->requires_approval ? 'Requires approval' : 'Direct publish' }}</span>
                        </td>
                        <td class="tabular-nums">{{ $p->priority }}</td>
                        <td class="whitespace-nowrap text-right">
                            <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="edit({{ $p->id }})" aria-label="Edit {{ $p->name }}" />
                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="delete({{ $p->id }})" wire:confirm="Delete this policy?" aria-label="Delete {{ $p->name }}" />
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-empty-state icon="scale" title="No policies" description="The default rule applies to everything." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <flux:modal name="policy" class="md:w-[34rem]">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? 'Edit policy' : 'New policy' }}</flux:heading>
            <flux:input wire:model="name" label="Name" required />
            <flux:textarea wire:model="description" label="Description" rows="2" />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="role" label="Staff role">
                    <flux:select.option value="">Any role</flux:select.option>
                    @foreach ($roles as $value => $label)<flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>@endforeach
                </flux:select>
                <flux:select wire:model="media_type" label="Media type">
                    <flux:select.option value="">Any type</flux:select.option>
                    @foreach ($types as $t)<flux:select.option value="{{ $t->value }}">{{ $t->label() }}</flux:select.option>@endforeach
                </flux:select>
                <flux:select wire:model="access_level" label="Access level">
                    <flux:select.option value="">Any</flux:select.option>
                    <flux:select.option value="public">Public</flux:select.option>
                    <flux:select.option value="restricted">Restricted</flux:select.option>
                </flux:select>
                <flux:select wire:model="sensitive" label="Sensitivity">
                    <flux:select.option value="">Any</flux:select.option>
                    <flux:select.option value="no">Non-sensitive only</flux:select.option>
                </flux:select>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="priority" type="number" min="0" label="Priority" description="Higher wins." />
                <div class="space-y-3 pt-6">
                    <flux:checkbox wire:model="requires_approval" label="Requires approval" />
                    <flux:checkbox wire:model="is_active" label="Active" />
                </div>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>
</div>

<div class="fade-in">
    <x-page-header eyebrow="Administration" title="Staff & roles" description="Staff appear here after their first sign-in from Koha. Koha decides who may open the MAM (plugin permission check); roles here decide what they may do inside it." />

    <div class="mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @foreach ($roles->except('suspended') as $key => $role)
            <div class="card p-4">
                <p class="font-medium text-zinc-900">{{ $role['label'] }}</p>
                <p class="mt-1 text-xs leading-relaxed text-zinc-500">{{ in_array('*', $role['capabilities']) ? 'All capabilities' : collect($role['capabilities'])->map(fn ($c) => str_replace(['media.', '_'], ['', ' '], $c))->implode(', ') }}</p>
            </div>
        @endforeach
    </div>

    <div class="card">
        <div class="border-b border-zinc-100 p-4">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search by name or Koha user ID" class="max-w-sm" />
        </div>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead><tr><th>Staff member</th><th>Koha</th><th>Last sign-in</th><th>Role</th></tr></thead>
                <tbody>
                    @foreach ($users as $u)
                        <tr wire:key="u-{{ $u->id }}">
                            <td>
                                <div class="flex items-center gap-3">
                                    <span class="grid size-9 place-items-center rounded-lg bg-brand-100 text-xs font-semibold text-brand">{{ $u->initials() }}</span>
                                    <div><p class="font-medium text-zinc-900">{{ $u->name() }}</p><p class="text-xs text-zinc-500">{{ $u->branch_code ?? '—' }}</p></div>
                                </div>
                            </td>
                            <td class="text-xs text-zinc-600"><span class="font-mono">{{ $u->koha_userid }}</span><br>patron #{{ $u->koha_patron_id }}</td>
                            <td class="whitespace-nowrap text-xs text-zinc-500">{{ $u->last_authenticated_at?->diffForHumans() ?? 'Never' }}</td>
                            <td>
                                <select wire:change="setRole({{ $u->id }}, $event.target.value)" aria-label="Role for {{ $u->name() }}"
                                        class="rounded-lg border border-zinc-200 bg-white py-1.5 pl-2 pr-8 text-sm focus:border-brand focus:outline-none focus:ring-4 focus:ring-brand/10">
                                    @foreach ($roles as $key => $role)
                                        <option value="{{ $key }}" @selected($u->role === $key)>{{ $role['label'] }}</option>
                                    @endforeach
                                </select>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-6">{{ $users->links() }}</div>
</div>

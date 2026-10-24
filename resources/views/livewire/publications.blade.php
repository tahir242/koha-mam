<div wire:poll.15s class="fade-in">
    <x-page-header eyebrow="Koha integration" title="Publication outbox"
                   description="Each MARC 856 change is stored here before it is sent to Koha, retried with backoff when Koha is unavailable, and verified by reading the record back. Nothing is marked published until verification succeeds." />

    <div class="mb-6 grid gap-4 sm:grid-cols-4">
        <x-stat-card label="Pending" :value="($counts['pending'] ?? 0) + ($counts['running'] ?? 0)" icon="clock" tone="sky" />
        <x-stat-card label="Succeeded" :value="$counts['succeeded'] ?? 0" icon="check-circle" tone="teal" />
        <x-stat-card label="Failed" :value="$counts['failed'] ?? 0" icon="x-circle" :tone="($counts['failed'] ?? 0) ? 'red' : 'sky'" />
        <x-stat-card label="Needs review" :value="$counts['needs_review'] ?? 0" icon="exclamation-triangle" :tone="($counts['needs_review'] ?? 0) ? 'amber' : 'sky'" />
    </div>

    <div class="mb-4 flex gap-1 rounded-xl bg-zinc-100 p-1 text-sm sm:inline-flex" role="tablist">
        @foreach (['attention' => 'Needs attention / in progress', 'succeeded' => 'Succeeded', 'all' => 'All'] as $key => $label)
            <button type="button" role="tab" aria-selected="{{ $filter === $key ? 'true' : 'false' }}" wire:click="$set('filter', '{{ $key }}')"
                    @class(['rounded-lg px-3 py-1.5 font-medium', 'bg-white text-zinc-900 shadow-sm' => $filter === $key, 'text-zinc-500 hover:text-zinc-800' => $filter !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    <div class="card overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Asset</th><th>Record</th><th>Action</th><th>Status</th><th>Attempts</th><th>Updated</th><th></th></tr></thead>
            <tbody>
                @forelse ($operations as $op)
                    <tr wire:key="op-{{ $op->id }}">
                        <td><a href="{{ route('assets.show', $op->asset) }}" wire:navigate class="font-medium text-zinc-900 hover:text-brand">{{ \Illuminate\Support\Str::limit($op->asset->title, 50) }}</a>
                            @if ($op->last_error)<p class="mt-0.5 max-w-md text-xs text-red-600">{{ $op->last_error }}</p>@endif
                        </td>
                        <td class="font-mono text-xs">#{{ $op->biblio_id }}@if ($op->item_id)<span class="block text-zinc-400">item #{{ $op->item_id }}</span>@endif</td>
                        <td class="capitalize">{{ $op->action }}</td>
                        <td>
                            <span @class(['rounded-full px-2 py-0.5 text-xs font-medium', 'bg-emerald-50 text-emerald-700' => $op->status->value === 'succeeded', 'bg-red-50 text-red-700' => $op->status->value === 'failed', 'bg-amber-50 text-amber-800' => $op->status->value === 'needs_review', 'bg-sky-50 text-sky-700' => $op->status->isOpen(), 'bg-zinc-100 text-zinc-600' => $op->status->value === 'superseded'])>{{ str_replace('_', ' ', $op->status->value) }}</span>
                            @if ($op->status->value === 'pending' && $op->available_at?->isFuture())<p class="mt-1 text-xs text-zinc-500">retry {{ $op->available_at->diffForHumans() }}</p>@endif
                        </td>
                        <td class="tabular-nums">{{ $op->attempts }}</td>
                        <td class="whitespace-nowrap text-xs text-zinc-500">{{ $op->updated_at->diffForHumans() }}</td>
                        <td class="text-right">
                            @if (in_array($op->status->value, ['failed', 'needs_review']) && auth()->user()->can('media.publish'))
                                <flux:button size="xs" icon="arrow-path" wire:click="retry({{ $op->id }})">Retry</flux:button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><x-empty-state icon="check-circle" title="Nothing here" description="No publication operations match this filter." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-6">{{ $operations->links() }}</div>
</div>

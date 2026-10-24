<div @if ($processing->isNotEmpty()) wire:poll.10s @endif class="fade-in">
    <x-page-header eyebrow="{{ now()->format('l, j F Y') }}" title="Good {{ now()->hour < 12 ? 'morning' : (now()->hour < 17 ? 'afternoon' : 'evening') }}, {{ \Illuminate\Support\Str::before(auth()->user()->name(), ' ') }}"
                   description="Digital resources attached to Koha bibliographic records, their processing and publication status.">
        <x-slot:actions>
            <span @class([
                'inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium ring-1 ring-inset',
                'bg-emerald-50 text-emerald-700 ring-emerald-200' => $koha['ok'],
                'bg-red-50 text-red-700 ring-red-200' => ! $koha['ok'],
            ]) title="{{ $koha['message'] }}">
                <span @class(['size-2 rounded-full', 'bg-emerald-500' => $koha['ok'], 'bg-red-500' => ! $koha['ok']])></span>
                Koha {{ $koha['ok'] ? 'connected' : 'unreachable' }}
                @if ($koha['latency_ms']) <span class="text-emerald-600/70">· {{ $koha['latency_ms'] }} ms</span> @endif
            </span>
            <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="refreshKoha" aria-label="Re-check Koha connectivity" />
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="Media assets" :value="number_format($totals['all'])" icon="rectangle-stack" :href="route('library')" />
        <x-stat-card label="Published" :value="number_format($totals['published'])" icon="globe-alt" tone="teal" :href="route('library', ['state' => 'published'])" :hint="number_format($totals['views_30d']).' public views in 30 days'" />
        <x-stat-card label="Awaiting approval" :value="number_format($totals['pending'])" icon="check-badge" tone="amber" :href="auth()->user()->can('media.review') ? route('approvals') : null" />
        <x-stat-card label="Failed publications" :value="number_format($totals['failed_publications'])" icon="exclamation-triangle" :tone="$totals['failed_publications'] ? 'red' : 'sky'" :href="route('publications')" />
    </div>

    <div class="mt-8 grid gap-6 xl:grid-cols-3">
        <section class="card xl:col-span-2" aria-labelledby="recent-heading">
            <div class="flex items-center justify-between border-b border-zinc-100 px-6 py-4">
                <h2 id="recent-heading" class="font-semibold text-zinc-900">Recent uploads</h2>
                <a href="{{ route('library') }}" wire:navigate class="text-sm font-medium text-brand hover:underline">View library</a>
            </div>
            @forelse ($recent as $asset)
                <a href="{{ route('assets.show', $asset) }}" wire:navigate class="flex items-center gap-4 border-b border-zinc-100 px-6 py-3.5 transition last:border-0 hover:bg-zinc-50/80">
                    <x-asset-thumb :asset="$asset" size="sm" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-zinc-900">{{ $asset->title }}</p>
                        <p class="truncate text-xs text-zinc-500">
                            {{ $asset->snapshot('title', 'Record '.$asset->biblio_id) }} · {{ $asset->created_at->diffForHumans() }}
                            @if ($asset->creator) · {{ $asset->creator->name() }} @endif
                        </p>
                    </div>
                    <x-status-badge :state="$asset->state" />
                </a>
            @empty
                <x-empty-state icon="cloud-arrow-up" title="No assets yet" description="Open a bibliographic record in Koha and choose “Attach Media” to attach the first resource." />
            @endforelse
        </section>

        <section class="card" aria-labelledby="processing-heading">
            <div class="border-b border-zinc-100 px-6 py-4">
                <h2 id="processing-heading" class="font-semibold text-zinc-900">Processing</h2>
            </div>
            <div class="divide-y divide-zinc-100">
                @forelse ($processing as $version)
                    <a href="{{ route('assets.show', $version->asset) }}" wire:navigate class="block px-6 py-3.5 hover:bg-zinc-50/80">
                        <div class="flex items-center justify-between gap-3 text-sm">
                            <span class="truncate font-medium text-zinc-800">{{ $version->asset->title }}</span>
                            <span class="shrink-0 text-xs tabular-nums text-zinc-500">{{ $version->processing_progress }}%</span>
                        </div>
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-zinc-100" role="progressbar" aria-valuenow="{{ $version->processing_progress }}" aria-valuemin="0" aria-valuemax="100">
                            <div class="h-full rounded-full bg-brand transition-all duration-700" style="width: {{ $version->processing_progress }}%"></div>
                        </div>
                        <p class="mt-1.5 text-xs capitalize text-zinc-500">{{ str_replace('_', ' ', $version->processing_stage ?? $version->status->value) }}</p>
                    </a>
                @empty
                    <x-empty-state icon="cpu-chip" title="Queue is clear" description="No media is being processed right now." />
                @endforelse

                @foreach ($failedProcessing as $version)
                    <a href="{{ route('assets.show', $version->asset) }}" wire:navigate class="flex items-start gap-3 px-6 py-3.5 hover:bg-red-50/50">
                        <flux:icon name="exclamation-circle" variant="mini" class="mt-0.5 size-4 shrink-0 text-red-500" />
                        <span class="min-w-0 text-sm">
                            <span class="block truncate font-medium text-zinc-800">{{ $version->asset->title }}</span>
                            <span class="line-clamp-2 text-xs text-red-600">{{ $version->processing_error }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
    </div>

    @if ($mostViewed->isNotEmpty())
        <section class="card mt-6" aria-labelledby="most-viewed-heading">
            <div class="flex items-center justify-between border-b border-zinc-100 px-6 py-4">
                <h2 id="most-viewed-heading" class="font-semibold text-zinc-900">Most viewed</h2>
                <a href="{{ route('library', ['sort' => 'views']) }}" wire:navigate class="text-sm font-medium text-brand hover:underline">All by views</a>
            </div>
            <ol class="divide-y divide-zinc-100">
                @foreach ($mostViewed as $asset)
                    <li>
                        <a href="{{ route('assets.show', $asset) }}" wire:navigate class="flex items-center gap-4 px-6 py-3 hover:bg-zinc-50/80">
                            <span class="w-5 shrink-0 text-right text-sm font-semibold tabular-nums text-zinc-400">{{ $loop->iteration }}</span>
                            <x-asset-thumb :asset="$asset" size="sm" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-zinc-900">{{ $asset->title }}</span>
                                <span class="block truncate text-xs text-zinc-500">{{ $asset->snapshot('title', 'Record '.$asset->biblio_id) }}@if ($asset->isItemLevel()) · {{ $asset->itemLabel() }}@endif</span>
                            </span>
                            <span class="shrink-0 text-sm font-medium tabular-nums text-zinc-700">{{ $asset->viewsShort() }}</span>
                        </a>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    <div class="mt-6 grid gap-6 xl:grid-cols-3">
        <section class="card" aria-labelledby="storage-heading">
            <div class="flex items-center justify-between border-b border-zinc-100 px-6 py-4">
                <h2 id="storage-heading" class="font-semibold text-zinc-900">Storage</h2>
                @can('media.manage_storage')
                    <a href="{{ route('admin.storage') }}" wire:navigate class="text-sm font-medium text-brand hover:underline">Manage</a>
                @endcan
            </div>
            <ul class="divide-y divide-zinc-100">
                @foreach ($storage as $row)
                    @php $b = $row['backend']; @endphp
                    <li class="px-6 py-4">
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium text-zinc-800">{{ $b->name }} @if ($b->is_default)<span class="ml-1 rounded bg-brand-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-brand">Default</span>@endif</span>
                            <span @class(['text-xs', 'text-emerald-600' => $b->health_status === 'healthy', 'text-red-600' => $b->health_status === 'failing', 'text-zinc-400' => $b->health_status === 'unknown'])>{{ ucfirst($b->health_status) }}</span>
                        </div>
                        <p class="mt-1 text-xs text-zinc-500">{{ \Illuminate\Support\Number::fileSize($row['used'], 1) }} used @if ($b->capacity_bytes) of {{ \Illuminate\Support\Number::fileSize($b->capacity_bytes) }} @endif · {{ \App\Models\StorageBackend::DRIVERS[$b->driver] ?? $b->driver }}</p>
                        @if ($b->capacity_bytes)
                            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-zinc-100">
                                <div class="h-full rounded-full bg-brand-teal" style="width: {{ min(100, round($row['used'] / max(1, $b->capacity_bytes) * 100)) }}%"></div>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="card" aria-labelledby="koha-ops-heading">
            <div class="flex items-center justify-between border-b border-zinc-100 px-6 py-4">
                <h2 id="koha-ops-heading" class="font-semibold text-zinc-900">Publication issues</h2>
                <a href="{{ route('publications') }}" wire:navigate class="text-sm font-medium text-brand hover:underline">Outbox</a>
            </div>
            <ul class="divide-y divide-zinc-100">
                @forelse ($failedOps as $op)
                    <li class="px-6 py-3.5">
                        <a href="{{ route('assets.show', $op->asset) }}" wire:navigate class="text-sm font-medium text-zinc-800 hover:text-brand">{{ $op->asset->title }}</a>
                        <p class="mt-0.5 line-clamp-2 text-xs text-red-600">{{ $op->last_error }}</p>
                    </li>
                @empty
                    <x-empty-state icon="check-circle" title="All publications verified" description="Every Koha 856 update has been written and read back successfully." />
                @endforelse
            </ul>
        </section>

        <section class="card" aria-labelledby="activity-heading">
            <div class="border-b border-zinc-100 px-6 py-4">
                <h2 id="activity-heading" class="font-semibold text-zinc-900">Recent activity</h2>
            </div>
            <ol class="space-y-0 px-6 py-3">
                @forelse ($activity as $entry)
                    <li class="relative border-l border-zinc-200 py-2.5 pl-5">
                        <span class="absolute -left-[5px] top-4 size-2.5 rounded-full border-2 border-white bg-brand/60"></span>
                        <p class="text-sm text-zinc-800">{{ $entry->description }}</p>
                        <p class="text-xs text-zinc-500">{{ $entry->causer?->name() ?? 'System' }} · {{ $entry->created_at->diffForHumans() }}</p>
                    </li>
                @empty
                    <li class="py-6 text-center text-sm text-zinc-500">No activity yet.</li>
                @endforelse
            </ol>
        </section>
    </div>
</div>

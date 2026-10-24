<div class="fade-in">
    <x-page-header eyebrow="Library" title="Media assets" description="Every resource managed by the MAM, across all Koha records." />

    <div class="card mb-6 p-4">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
            <div class="relative flex-1">
                <label for="library-search" class="sr-only">Search</label>
                <flux:icon name="magnifying-glass" variant="mini" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
                <input id="library-search" type="search" wire:model.live.debounce.350ms="search" placeholder="Title, description, Koha record number or asset ID"
                       class="h-10 w-full rounded-xl border border-zinc-200 pl-9 pr-3 text-sm focus:border-brand focus:outline-none focus:ring-4 focus:ring-brand/10">
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:flex">
                <flux:select wire:model.live="type" size="sm" aria-label="Media type">
                    <flux:select.option value="">All types</flux:select.option>
                    @foreach ($types as $t)
                        <flux:select.option value="{{ $t->value }}">{{ $t->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="state" size="sm" aria-label="Status">
                    <flux:select.option value="">Any status</flux:select.option>
                    @foreach ($states as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="access" size="sm" aria-label="Access">
                    <flux:select.option value="">Any access</flux:select.option>
                    <flux:select.option value="public">Public</flux:select.option>
                    <flux:select.option value="restricted">Restricted</flux:select.option>
                </flux:select>
                <flux:select wire:model.live="sort" size="sm" aria-label="Sort by">
                    <flux:select.option value="newest">Newest first</flux:select.option>
                    <flux:select.option value="updated">Recently updated</flux:select.option>
                    <flux:select.option value="oldest">Oldest first</flux:select.option>
                    <flux:select.option value="title">Title A–Z</flux:select.option>
                    <flux:select.option value="record">Koha record</flux:select.option>
                    <flux:select.option value="views">Most viewed</flux:select.option>
                </flux:select>
            </div>
            <div class="flex items-center gap-1 rounded-xl bg-zinc-100 p-1" role="group" aria-label="Layout">
                <button type="button" wire:click="$set('view', 'grid')" @class(['rounded-lg p-1.5', 'bg-white shadow-sm text-zinc-900' => $view === 'grid', 'text-zinc-500' => $view !== 'grid']) aria-pressed="{{ $view === 'grid' ? 'true' : 'false' }}" aria-label="Grid view">
                    <flux:icon name="squares-2x2" variant="mini" class="size-4" />
                </button>
                <button type="button" wire:click="$set('view', 'table')" @class(['rounded-lg p-1.5', 'bg-white shadow-sm text-zinc-900' => $view === 'table', 'text-zinc-500' => $view !== 'table']) aria-pressed="{{ $view === 'table' ? 'true' : 'false' }}" aria-label="Table view">
                    <flux:icon name="list-bullet" variant="mini" class="size-4" />
                </button>
            </div>
        </div>
        @if ($search || $type || $state || $access)
            <div class="mt-3 flex items-center gap-2 text-xs text-zinc-500">
                <span>{{ number_format($assets->total()) }} result(s)</span>
                <button type="button" wire:click="clearFilters" class="font-medium text-brand hover:underline">Clear filters</button>
            </div>
        @endif
    </div>

    <div wire:loading.class="opacity-60" class="transition-opacity">
        @if ($assets->isEmpty())
            <div class="card">
                <x-empty-state icon="rectangle-stack" title="No assets found" description="Try a different search or filter." />
            </div>
        @elseif ($view === 'grid')
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($assets as $asset)
                    <a href="{{ route('assets.show', $asset) }}" wire:navigate wire:key="a-{{ $asset->id }}" class="card group overflow-hidden p-2 transition hover:-translate-y-0.5 hover:shadow-md">
                        <x-asset-thumb :asset="$asset" size="lg" />
                        <div class="px-2 pb-2 pt-3">
                            <p class="line-clamp-1 text-sm font-semibold text-zinc-900 group-hover:text-brand">{{ $asset->title }}</p>
                            <p class="mt-0.5 line-clamp-1 text-xs text-zinc-500">{{ $asset->snapshot('title', 'Koha record '.$asset->biblio_id) }}</p>
                            <div class="mt-3 flex items-center justify-between gap-2">
                                <x-status-badge :state="$asset->state" />
                                <span class="flex items-center gap-2 text-xs tabular-nums text-zinc-500">
                                    @if ($asset->isPublished()){{ $asset->viewsShort() }}@endif
                                    @if ($asset->access_level === \App\Enums\AccessLevel::Restricted)
                                        <flux:icon name="lock-closed" variant="mini" class="size-4 text-zinc-400" title="Restricted" />
                                    @endif
                                </span>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @else
            <div class="card overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Asset</th>
                            <th scope="col">Koha record</th>
                            <th scope="col">Type</th>
                            <th scope="col">Status</th>
                            <th scope="col">Access</th>
                            <th scope="col" class="text-right">Views</th>
                            <th scope="col">Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($assets as $asset)
                            <tr wire:key="r-{{ $asset->id }}">
                                <td>
                                    <a href="{{ route('assets.show', $asset) }}" wire:navigate class="flex items-center gap-3">
                                        <x-asset-thumb :asset="$asset" size="sm" />
                                        <span class="font-medium text-zinc-900 hover:text-brand">{{ $asset->title }}</span>
                                    </a>
                                </td>
                                <td class="max-w-xs">
                                    <a href="{{ route('biblios.show', $asset->biblio_id) }}" wire:navigate class="line-clamp-1 text-zinc-700 hover:text-brand">{{ $asset->snapshot('title', '—') }}</a>
                                    <span class="text-xs text-zinc-400">#{{ $asset->biblio_id }}</span>
                                    <x-item-badge :asset="$asset" class="ml-1" />
                                </td>
                                <td class="text-zinc-600">{{ $asset->media_type->label() }}</td>
                                <td><x-status-badge :state="$asset->state" /></td>
                                <td class="text-zinc-600">{{ $asset->access_level->label() }}</td>
                                <td class="whitespace-nowrap text-right tabular-nums text-zinc-700">{{ number_format($asset->view_count) }}</td>
                                <td class="whitespace-nowrap text-zinc-500">{{ $asset->updated_at->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="mt-6">{{ $assets->links() }}</div>
    </div>
</div>

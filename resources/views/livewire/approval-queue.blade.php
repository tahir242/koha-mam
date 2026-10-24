<div class="fade-in">
    <x-page-header eyebrow="Review" title="Awaiting approval" description="Resources submitted for publication. Oldest submissions first. You cannot approve resources you submitted yourself." />

    <div class="card divide-y divide-zinc-100">
        @forelse ($assets as $asset)
            <a href="{{ route('assets.show', $asset) }}" wire:navigate wire:key="p-{{ $asset->id }}" class="flex flex-col gap-4 p-5 transition hover:bg-zinc-50/80 sm:flex-row sm:items-center">
                <x-asset-thumb :asset="$asset" />
                <div class="min-w-0 flex-1">
                    <p class="font-medium text-zinc-900">{{ $asset->title }}</p>
                    <p class="mt-0.5 line-clamp-1 text-sm text-zinc-500">{{ $asset->snapshot('title', 'Koha record #'.$asset->biblio_id) }}</p>
                    <div class="mt-2 flex flex-wrap gap-2 text-xs">
                        <span class="rounded-md bg-zinc-100 px-2 py-0.5 text-zinc-600">{{ $asset->media_type->label() }}</span>
                        <span class="rounded-md bg-zinc-100 px-2 py-0.5 text-zinc-600">{{ $asset->access_level->label() }}</span>
                        @if ($asset->is_sensitive)<span class="rounded-md bg-red-50 px-2 py-0.5 font-medium text-red-700">Sensitive</span>@endif
                    </div>
                </div>
                <div class="text-sm text-zinc-500 sm:text-right">
                    <p>{{ $asset->submitter?->name() ?? '—' }}</p>
                    <p class="text-xs">{{ $asset->submitted_at?->diffForHumans() }}</p>
                </div>
                <flux:icon name="chevron-right" class="hidden size-5 text-zinc-300 sm:block" />
            </a>
        @empty
            <x-empty-state icon="check-badge" title="Nothing waiting" description="All submitted resources have been reviewed." />
        @endforelse
    </div>
    <div class="mt-6">{{ $assets->links() }}</div>
</div>

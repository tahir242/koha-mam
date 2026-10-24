@props(['asset'])

@php $latest = $asset->versions->first(); @endphp
<div {{ $attributes->merge(['class' => 'flex items-center gap-4 p-4']) }}>
    <x-asset-thumb :asset="$asset" />
    <div class="min-w-0 flex-1">
        <a href="{{ route('assets.show', $asset) }}" wire:navigate class="line-clamp-1 font-medium text-zinc-900 hover:text-brand">{{ $asset->title }}</a>
        <p class="mt-0.5 text-xs text-zinc-500">
            {{ $asset->media_type->label() }} · v{{ $latest?->version_number ?? 1 }} · {{ $asset->access_level->label() }}
            @if ($latest) · {{ \Illuminate\Support\Number::fileSize($latest->size_bytes, 1) }} @endif
        </p>
        <x-item-badge :asset="$asset" class="mt-1" />
        @if ($latest && ! $latest->status->isTerminal())
            <div class="mt-2 flex items-center gap-2">
                <div class="h-1 flex-1 overflow-hidden rounded-full bg-zinc-100">
                    <div class="h-full rounded-full bg-brand transition-all duration-700" style="width: {{ $latest->processing_progress }}%"></div>
                </div>
                <span class="text-[11px] capitalize text-zinc-500">{{ str_replace('_', ' ', $latest->processing_stage ?? $latest->status->value) }}</span>
            </div>
        @elseif ($latest?->status === \App\Enums\VersionStatus::Failed)
            <p class="mt-1 line-clamp-1 text-xs text-red-600">{{ $latest->processing_error }}</p>
        @endif
    </div>
    <div class="flex shrink-0 flex-col items-end gap-2">
        <x-status-badge :state="$asset->state" />
        @if ($asset->isPublished())
            <a href="{{ $asset->viewerUrl() }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-xs font-medium text-brand hover:underline">
                Public view <flux:icon name="arrow-up-right" variant="micro" class="size-3" />
            </a>
        @endif
    </div>
</div>

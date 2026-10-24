@props(['asset'])

{{-- Koha item an asset is attached to; nothing for a record-level asset. --}}
@if ($asset->isItemLevel())
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-md bg-sky-50 px-1.5 py-0.5 text-[11px] font-medium text-sky-700']) }} title="Attached to Koha item #{{ $asset->item_id }}">
        <flux:icon name="tag" variant="micro" class="size-3" /> {{ $asset->itemLabel() }}
    </span>
@endif

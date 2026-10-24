@props(['label', 'value', 'icon', 'tone' => 'brand', 'hint' => null, 'href' => null])
@php
    $tones = [
        'brand' => 'bg-brand-100 text-brand',
        'teal' => 'bg-emerald-50 text-brand-teal',
        'amber' => 'bg-amber-50 text-amber-700',
        'red' => 'bg-red-50 text-brand-red',
        'sky' => 'bg-sky-50 text-sky-700',
    ];
@endphp
<{{ $href ? 'a' : 'div' }} @if ($href) href="{{ $href }}" wire:navigate @endif {{ $attributes->class(['card group flex items-start gap-4 p-5 transition', 'hover:-translate-y-0.5 hover:shadow-md' => $href]) }}>
    <span class="grid size-11 shrink-0 place-items-center rounded-xl {{ $tones[$tone] ?? $tones['brand'] }}">
        <flux:icon :name="$icon" variant="outline" class="size-5" />
    </span>
    <div class="min-w-0">
        <p class="text-sm text-zinc-500">{{ $label }}</p>
        <p class="stat-value mt-0.5">{{ $value }}</p>
        @if ($hint)
            <p class="mt-1 text-xs text-zinc-400">{{ $hint }}</p>
        @endif
    </div>
</{{ $href ? 'a' : 'div' }}>

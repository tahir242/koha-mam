@props(['variant' => 'info', 'icon' => null, 'heading' => null])
@php
    // Colour pairs chosen for WCAG AA contrast on their backgrounds.
    $styles = [
        'info' => ['bg-sky-50 ring-sky-200', 'text-sky-900', 'text-sky-800', 'information-circle'],
        'success' => ['bg-emerald-50 ring-emerald-200', 'text-emerald-900', 'text-emerald-800', 'check-circle'],
        'warning' => ['bg-amber-50 ring-amber-300', 'text-amber-950', 'text-amber-900', 'exclamation-triangle'],
        'danger' => ['bg-red-50 ring-red-200', 'text-red-900', 'text-red-800', 'x-circle'],
    ][$variant] ?? ['bg-zinc-50 ring-zinc-200', 'text-zinc-900', 'text-zinc-700', 'information-circle'];
@endphp
<div {{ $attributes->class(['flex gap-3 rounded-xl p-4 ring-1 ring-inset', $styles[0]]) }} role="{{ in_array($variant, ['warning', 'danger']) ? 'alert' : 'status' }}">
    <flux:icon :name="$icon ?? $styles[3]" variant="mini" class="mt-0.5 size-5 shrink-0 {{ $styles[1] }}" />
    <div class="min-w-0 flex-1 text-sm">
        @if ($heading)<p class="font-semibold {{ $styles[1] }}">{{ $heading }}</p>@endif
        <div @class(['leading-relaxed', $styles[2], 'mt-0.5' => $heading])>{{ $slot }}</div>
        @isset($actions)<div class="mt-3">{{ $actions }}</div>@endisset
    </div>
</div>

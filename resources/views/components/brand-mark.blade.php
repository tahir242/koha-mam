@props(['size' => 'md', 'inverse' => false])
@php
    $box = $size === 'sm' ? 'size-8 text-[11px]' : 'size-10 text-xs';
@endphp
<span {{ $attributes->class(['inline-flex items-center gap-3']) }}>
    <span @class([
        'grid shrink-0 place-items-center rounded-xl font-bold tracking-wider shadow-sm',
        $box,
        'bg-white text-brand' => $inverse,
        'bg-brand text-white' => ! $inverse,
    ])>
        <svg viewBox="0 0 24 24" class="size-1/2" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="M4 5.5A1.5 1.5 0 0 1 5.5 4H10l2 2h6.5A1.5 1.5 0 0 1 20 7.5v11a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 18.5z" stroke-linejoin="round"/>
            <path d="M12 10v6m-3-3 3-3 3 3" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </span>
    <span class="leading-tight">
        <span @class(['block text-sm font-semibold tracking-tight', 'text-white' => $inverse, 'text-zinc-900' => ! $inverse])>{{ config('dam.institution.short_name') }} MAM</span>
        <span @class(['block text-[11px]', 'text-white/60' => $inverse, 'text-zinc-500' => ! $inverse])>{{ config('dam.institution.library_name') }}</span>
    </span>
</span>

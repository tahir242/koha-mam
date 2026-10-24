@props(['state'])
@php
    $tones = [
        'zinc' => 'bg-zinc-100 text-zinc-700 ring-zinc-200',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'red' => 'bg-red-50 text-red-700 ring-red-200',
        'indigo' => 'bg-indigo-50 text-indigo-700 ring-indigo-200',
        'amber' => 'bg-amber-50 text-amber-800 ring-amber-200',
        'teal' => 'bg-teal-50 text-teal-700 ring-teal-200',
        'cyan' => 'bg-cyan-50 text-cyan-700 ring-cyan-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    ];
    $tone = $state->tone();
@endphp
<span {{ $attributes->class(['inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset whitespace-nowrap', $tones[$tone] ?? $tones['zinc']]) }}>
    @if ($state->isProcessing() || $state->equals(\App\States\Asset\PublicationPending::class))
        <span class="size-1.5 animate-pulse rounded-full bg-current"></span>
    @else
        <span class="size-1.5 rounded-full bg-current opacity-70"></span>
    @endif
    {{ $state->label() }}
</span>

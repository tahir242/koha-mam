@props(['title', 'eyebrow' => null, 'description' => null])
<div {{ $attributes->class(['mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between']) }}>
    <div class="min-w-0">
        @if ($eyebrow)
            <p class="eyebrow mb-2">{{ $eyebrow }}</p>
        @endif
        <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 sm:text-[28px]">{{ $title }}</h1>
        @if ($description)
            <p class="mt-1.5 max-w-2xl text-sm text-zinc-500">{{ $description }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>

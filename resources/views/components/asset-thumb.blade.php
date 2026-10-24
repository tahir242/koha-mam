@props(['asset', 'size' => 'md'])
@php
    $thumb = $asset->currentVersion?->file(\App\Enums\FileRole::Thumbnail);
    $url = $thumb ? app(\App\Delivery\MediaAccess::class)->signedFileUrl($asset, $thumb) : null;
    $classes = match ($size) {
        'sm' => 'size-11 rounded-lg',
        'lg' => 'aspect-[4/3] w-full rounded-xl',
        default => 'size-14 rounded-xl',
    };
    $tint = match ($asset->media_type) {
        \App\Enums\MediaType::Document => 'from-sky-50 to-sky-100 text-sky-600',
        \App\Enums\MediaType::Image => 'from-violet-50 to-violet-100 text-violet-600',
        \App\Enums\MediaType::Video => 'from-rose-50 to-rose-100 text-rose-600',
        \App\Enums\MediaType::Audio => 'from-amber-50 to-amber-100 text-amber-700',
        default => 'from-zinc-50 to-zinc-100 text-zinc-500',
    };
@endphp
<div {{ $attributes->class(['relative shrink-0 overflow-hidden bg-gradient-to-br ring-1 ring-inset ring-black/5', $classes, $tint]) }}>
    @if ($url)
        <img src="{{ $url }}" alt="" loading="lazy" class="size-full object-cover" draggable="false">
    @else
        <div class="grid size-full place-items-center">
            <flux:icon :name="$asset->media_type->icon()" variant="outline" @class(['size-6' => $size !== 'lg', 'size-10' => $size === 'lg']) />
        </div>
    @endif
    @if ($size === 'lg')
        <span class="absolute left-2 top-2 rounded-md bg-white/90 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-zinc-700 shadow-sm">{{ $asset->media_type->label() }}</span>
    @endif
</div>

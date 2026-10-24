@props(['icon' => 'inbox', 'title', 'description' => null])
<div {{ $attributes->class(['flex flex-col items-center justify-center px-6 py-14 text-center']) }}>
    <span class="mb-4 grid size-12 place-items-center rounded-2xl bg-zinc-100 text-zinc-400">
        <flux:icon :name="$icon" variant="outline" class="size-6" />
    </span>
    <p class="text-sm font-medium text-zinc-900">{{ $title }}</p>
    @if ($description)
        <p class="mt-1 max-w-sm text-sm text-zinc-500">{{ $description }}</p>
    @endif
    {{ $slot }}
</div>

@php
    $title = $first->snapshot('title') ?: $first->title;
    $details = $first->itemDetails();
@endphp
<x-layouts::public :title="'Item #'.$item.' · '.$title" :description="$title">
    <x-slot:nav>
        @if (config('dam.koha.opac_url'))
            <a href="{{ config('dam.koha.opac_url') }}/cgi-bin/koha/opac-detail.pl?biblionumber={{ $first->biblio_id }}" class="text-sm font-medium text-brand hover:underline">Catalogue record</a>
        @endif
    </x-slot:nav>

    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-teal">Digital resources for item #{{ $item }}</p>
        <h1 class="mt-2 font-serif text-3xl font-semibold leading-tight text-zinc-900">{{ $title }}</h1>
        @if ($details)
            <p class="mt-1 text-sm text-zinc-600">{{ $details }}</p>
        @endif

        <ul class="mt-8 divide-y divide-zinc-100 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-black/5">
            @foreach ($assets as $asset)
                <li>
                    <a href="{{ route('viewer.show', $asset->uuid) }}" class="flex items-center gap-4 px-5 py-4 hover:bg-zinc-50">
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium text-zinc-900">{{ $asset->title }}</span>
                            <span class="mt-0.5 block text-xs text-zinc-500">
                                {{ $asset->media_type->label() }} · {{ $asset->viewsShort() }}
                                @if ($asset->access_level === \App\Enums\AccessLevel::Restricted) · Staff only @endif
                            </span>
                        </span>
                        <span class="text-sm font-medium text-brand" aria-hidden="true">Open →</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</x-layouts::public>

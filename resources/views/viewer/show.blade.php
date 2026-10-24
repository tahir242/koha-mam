@php
    use App\Enums\MediaType;
    $type = $asset->media_type;
    $title = $asset->snapshot('title') ?: $asset->title;
    $wm = $watermark ? trim($watermark.' · '.now()->format('Y-m-d')) : null;
@endphp
<x-layouts::public :title="$asset->title" :description="$asset->description ?: $title" :noindex="$isPreview || $asset->access_level === \App\Enums\AccessLevel::Restricted">
    <x-slot:nav>
        @if (config('dam.koha.opac_url'))
            <a href="{{ config('dam.koha.opac_url') }}/cgi-bin/koha/opac-detail.pl?biblionumber={{ $asset->biblio_id }}" class="text-sm font-medium text-brand hover:underline">Catalogue record</a>
        @endif
    </x-slot:nav>

    @if ($isPreview)
        <div class="bg-amber-100 px-4 py-2 text-center text-sm font-medium text-amber-900" role="status">
            Staff preview — this resource is not public ({{ $asset->state->label() }}).
        </div>
    @endif

    <div class="mx-auto max-w-6xl px-4 pt-8 sm:px-6">
        <div class="mb-6">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-teal">{{ $type->label() }}</p>
            <h1 class="mt-2 font-serif text-3xl font-semibold leading-tight text-zinc-900 sm:text-4xl">{{ $asset->title }}</h1>
            @if ($asset->title !== $title)
                <p class="mt-1 text-base text-zinc-600">from <span class="italic">{{ $title }}</span></p>
            @endif
            @unless ($isPreview)
                <p class="mt-2 text-sm text-zinc-500"><span data-view-count>{{ $asset->viewsLabel() }}</span>@if ($asset->published_at) · {{ $asset->published_at->format('j M Y') }}@endif</p>
            @endunless
        </div>

        <div class="grid gap-8 lg:grid-cols-[1fr_300px]">
            {{-- Media --}}
            <div id="viewer"
                 class="relative overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-black/5"
                 data-type="{{ $type->value }}"
                 data-sources="{{ json_encode($sources) }}"
                 data-pdfjs-assets="{{ asset('vendor/pdfjs') }}"
                 @if ($viewTracking)
                     data-view-endpoint="{{ $viewTracking['endpoint'] }}"
                     data-view-token="{{ $viewTracking['token'] }}"
                     data-view-seconds="{{ $viewTracking['seconds'] }}"
                 @endif
                 data-printable="false">

                <div data-loading class="absolute inset-0 z-10 grid place-items-center bg-white/80" aria-live="polite">
                    <div class="flex items-center gap-3 text-sm text-zinc-600">
                        <svg class="size-5 animate-spin text-brand" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity=".2" stroke-width="3"/><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                        Loading…
                    </div>
                </div>
                <div data-error hidden class="absolute inset-0 z-20 grid place-items-center bg-white p-6 text-center" role="alert">
                    <div>
                        <p class="font-medium text-zinc-900">This resource could not be displayed.</p>
                        <p class="mt-1 text-sm text-zinc-500">Please reload the page. If the problem continues, contact the library.</p>
                    </div>
                </div>

                @if ($type === MediaType::Document)
                    <div class="flex flex-wrap items-center gap-2 border-b border-zinc-100 px-3 py-2 text-sm" role="toolbar" aria-label="Document controls">
                        <div class="flex items-center gap-1" data-pdf-only>
                            <button type="button" data-action="prev" class="rounded-lg p-2 text-zinc-600 hover:bg-zinc-100" aria-label="Previous page">
                                <svg viewBox="0 0 20 20" class="size-4" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd"/></svg>
                            </button>
                            <label class="flex items-center gap-1.5 text-zinc-600">
                                <span class="sr-only">Page</span>
                                <input data-page-input type="number" min="1" value="1" class="w-14 rounded-md border border-zinc-200 px-2 py-1 text-center tabular-nums">
                                <span>/ <span data-page-count>–</span></span>
                            </label>
                            <button type="button" data-action="next" class="rounded-lg p-2 text-zinc-600 hover:bg-zinc-100" aria-label="Next page">
                                <svg viewBox="0 0 20 20" class="size-4" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/></svg>
                            </button>
                        </div>
                        <div class="mx-1 h-5 w-px bg-zinc-200" data-pdf-only></div>
                        <div class="flex items-center gap-1" data-pdf-only>
                            <button type="button" data-action="zoom-out" class="rounded-lg p-2 text-zinc-600 hover:bg-zinc-100" aria-label="Zoom out">−</button>
                            <span data-zoom-label class="w-12 text-center text-xs tabular-nums text-zinc-500">100%</span>
                            <button type="button" data-action="zoom-in" class="rounded-lg p-2 text-zinc-600 hover:bg-zinc-100" aria-label="Zoom in">+</button>
                            <button type="button" data-action="fit" class="rounded-lg px-2 py-1 text-xs text-zinc-600 hover:bg-zinc-100">Fit width</button>
                        </div>
                        <form data-search-form data-pdf-only class="ml-auto flex items-center gap-1" role="search">
                            <label class="sr-only" for="pdf-search">Search in document</label>
                            <input id="pdf-search" data-search-input type="search" placeholder="Search" class="w-36 rounded-md border border-zinc-200 px-2 py-1 sm:w-48">
                            <button type="button" data-action="search-prev" class="rounded-lg p-1.5 text-zinc-600 hover:bg-zinc-100" aria-label="Previous match">↑</button>
                            <button type="button" data-action="search-next" class="rounded-lg p-1.5 text-zinc-600 hover:bg-zinc-100" aria-label="Next match">↓</button>
                            <span data-search-status class="min-w-16 text-xs text-zinc-500" aria-live="polite"></span>
                        </form>
                        <button type="button" data-action="fullscreen" class="rounded-lg p-2 text-zinc-600 hover:bg-zinc-100" aria-label="Full screen">
                            <svg viewBox="0 0 20 20" class="size-4" fill="currentColor" aria-hidden="true"><path d="M3.28 2.22a.75.75 0 0 0-1.06 1.06L5.44 6.5H2.75a.75.75 0 0 0 0 1.5h4.5A.75.75 0 0 0 8 7.25v-4.5a.75.75 0 0 0-1.5 0v2.69L3.28 2.22ZM13.5 2.75a.75.75 0 0 0-1.5 0v4.5c0 .414.336.75.75.75h4.5a.75.75 0 0 0 0-1.5h-2.69l3.22-3.22a.75.75 0 0 0-1.06-1.06L13.5 5.44V2.75ZM3.28 17.78l3.22-3.22v2.69a.75.75 0 0 0 1.5 0v-4.5a.75.75 0 0 0-.75-.75h-4.5a.75.75 0 0 0 0 1.5h2.69l-3.22 3.22a.75.75 0 1 0 1.06 1.06ZM13.5 14.56l3.22 3.22a.75.75 0 1 0 1.06-1.06l-3.22-3.22h2.69a.75.75 0 0 0 0-1.5h-4.5a.75.75 0 0 0-.75.75v4.5a.75.75 0 0 0 1.5 0v-2.69Z"/></svg>
                        </button>
                    </div>
                    <div class="pdf-stage">
                        <div data-pdf-container tabindex="0" aria-label="Document pages"><div class="pdfViewer"></div></div>
                        @if ($wm)<div class="watermark" aria-hidden="true">@for ($i = 0; $i < 18; $i++)<span>{{ $wm }}</span>@endfor</div>@endif
                    </div>
                @elseif ($type === MediaType::Video)
                    <div class="relative bg-black">
                        <video controls playsinline preload="metadata" controlslist="nodownload noremoteplayback" disablepictureinpicture
                               @if ($sources['poster']) poster="{{ $sources['poster'] }}" @endif
                               class="aspect-video w-full" aria-label="{{ $asset->title }}"></video>
                        @if ($wm)<div class="watermark on-dark" aria-hidden="true">@for ($i = 0; $i < 9; $i++)<span>{{ $wm }}</span>@endfor</div>@endif
                    </div>
                @elseif ($type === MediaType::Audio)
                    <div class="flex flex-col items-center gap-6 bg-gradient-to-br from-brand to-[#0b2a3f] px-6 py-14 text-white">
                        <span class="grid size-20 place-items-center rounded-full bg-white/10 ring-1 ring-white/20">
                            <svg viewBox="0 0 24 24" class="size-9" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="m9 9 10.5-3m0 6.553v3.75a2.25 2.25 0 0 1-1.632 2.163l-1.32.377a1.803 1.803 0 1 1-.99-3.467l2.31-.66a2.25 2.25 0 0 0 1.632-2.163Zm0 0V2.25L9 5.25v10.303m0 0v3.75a2.25 2.25 0 0 1-1.632 2.163l-1.32.377a1.803 1.803 0 0 1-.99-3.467l2.31-.66A2.25 2.25 0 0 0 9 15.553Z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                        <p class="text-center font-serif text-xl">{{ $asset->title }}</p>
                        <audio controls preload="metadata" controlslist="nodownload noremoteplayback" src="{{ $sources['audio'] }}" class="w-full max-w-xl" aria-label="{{ $asset->title }}"></audio>
                    </div>
                @elseif ($type === MediaType::Image)
                    <div class="flex items-center justify-end gap-1 border-b border-zinc-100 px-3 py-2" role="toolbar" aria-label="Image controls">
                        <button type="button" data-action="zoom-toggle" aria-pressed="false" class="rounded-lg px-2 py-1 text-xs text-zinc-600 hover:bg-zinc-100">Actual size</button>
                        <button type="button" data-action="fullscreen" class="rounded-lg px-2 py-1 text-xs text-zinc-600 hover:bg-zinc-100">Full screen</button>
                    </div>
                    <div class="relative">
                        <div class="image-stage" data-image-stage>
                            <img data-image src="{{ $sources['large'] }}" @if ($sources['medium']) srcset="{{ $sources['medium'] }} 1200w, {{ $sources['large'] }} 2400w" sizes="(max-width: 1024px) 100vw, 900px" @endif
                                 width="{{ $sources['width'] }}" height="{{ $sources['height'] }}" alt="{{ $asset->description ?: $asset->title }}" draggable="false">
                        </div>
                        @if ($wm)<div class="watermark on-dark" aria-hidden="true">@for ($i = 0; $i < 12; $i++)<span>{{ $wm }}</span>@endfor</div>@endif
                    </div>
                @endif
            </div>

            {{-- Metadata --}}
            <aside class="space-y-6 text-sm">
                @if ($asset->description)
                    <section>
                        <h2 class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">About this resource</h2>
                        <p class="mt-2 leading-relaxed text-zinc-700">{{ $asset->description }}</p>
                    </section>
                @endif

                <section class="rounded-2xl border border-black/5 bg-white p-5">
                    <h2 class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">Catalogue record</h2>
                    <p class="mt-3 font-serif text-lg font-semibold leading-snug text-zinc-900">{{ $title }}</p>
                    @if ($asset->snapshot('subtitle'))<p class="text-zinc-600">{{ $asset->snapshot('subtitle') }}</p>@endif
                    <dl class="mt-4 space-y-3">
                        @foreach (['author' => 'Author', 'edition' => 'Edition', 'publisher' => 'Publisher', 'publication_year' => 'Date', 'isbn' => 'ISBN'] as $key => $label)
                            @if ($asset->snapshot($key))
                                <div><dt class="text-xs text-zinc-500">{{ $label }}</dt><dd class="text-zinc-800">{{ $asset->snapshot($key) }}</dd></div>
                            @endif
                        @endforeach
                    </dl>
                    @if (config('dam.koha.opac_url'))
                        <a href="{{ config('dam.koha.opac_url') }}/cgi-bin/koha/opac-detail.pl?biblionumber={{ $asset->biblio_id }}" class="mt-4 inline-flex items-center gap-1 font-medium text-brand hover:underline">View in the library catalogue →</a>
                    @endif
                    <p class="mt-4 border-t border-zinc-100 pt-3 text-xs text-zinc-500">Bibliographic data from the {{ config('dam.institution.short_name') }} Library catalogue (Koha).</p>
                </section>

                <p class="text-xs leading-relaxed text-zinc-500">
                    This resource is provided for online viewing only by {{ config('dam.institution.name') }}. Reproduction or redistribution may be subject to copyright.
                </p>
            </aside>
        </div>
    </div>

    <x-slot:scripts>
        @vite(['resources/js/viewer.js'])
    </x-slot:scripts>
</x-layouts::public>

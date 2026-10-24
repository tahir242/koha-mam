<x-layouts::public title="Digital resources" :description="config('dam.institution.library_name').' digital resources'">
    <section class="mx-auto max-w-3xl px-4 py-24 text-center sm:px-6">
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-teal">{{ config('dam.institution.short_name') }} Library</p>
        <h1 class="mt-4 font-serif text-4xl font-semibold leading-tight text-zinc-900 sm:text-5xl">Digital resources from the library catalogue</h1>
        <p class="mx-auto mt-5 max-w-xl text-lg leading-relaxed text-zinc-600">
            Documents, images, audio and video are linked from records in the library catalogue. Find a title in the catalogue and follow its
            “View digital resource” link to open it here.
        </p>
        @if (config('dam.koha.opac_url'))
            <a href="{{ config('dam.koha.opac_url') }}" class="mt-8 inline-flex items-center gap-2 rounded-xl bg-brand px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-brand/90">
                Search the catalogue
                <svg viewBox="0 0 20 20" class="size-4" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M3 10a.75.75 0 0 1 .75-.75h10.64l-3.22-3.22a.75.75 0 1 1 1.06-1.06l4.5 4.5a.75.75 0 0 1 0 1.06l-4.5 4.5a.75.75 0 1 1-1.06-1.06l3.22-3.22H3.75A.75.75 0 0 1 3 10Z" clip-rule="evenodd"/></svg>
            </a>
        @endif
        <p class="mt-10 text-sm text-zinc-500">Library staff: open the Media Asset Management (MAM) system from a record in Koha.</p>
    </section>
</x-layouts::public>

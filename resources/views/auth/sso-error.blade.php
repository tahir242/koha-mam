<x-layouts::public title="Sign-in failed" :noindex="true">
    <section class="mx-auto max-w-md px-4 py-20 sm:px-6">
        <div class="rounded-2xl border border-black/5 bg-white p-8 shadow-sm" role="alert">
            <span class="grid size-12 place-items-center rounded-2xl bg-red-50 text-brand-red">
                <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
            <h1 class="mt-5 font-serif text-2xl font-semibold text-zinc-900">We couldn't sign you in</h1>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600">{{ $message }}</p>
            @if ($kohaUrl)
                <a href="{{ $kohaUrl }}" class="mt-6 inline-flex w-full items-center justify-center rounded-xl bg-brand px-4 py-3 text-sm font-semibold text-white transition hover:bg-brand/90">Return to Koha</a>
            @endif
        </div>
    </section>
</x-layouts::public>

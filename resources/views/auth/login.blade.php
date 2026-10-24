<x-layouts::public title="Staff sign-in" :noindex="true">
    <section class="mx-auto max-w-md px-4 py-20 sm:px-6">
        <div class="rounded-2xl border border-black/5 bg-white p-8 shadow-sm">
            <span class="grid size-12 place-items-center rounded-2xl bg-brand/10 text-brand">
                <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
            <h1 class="mt-5 font-serif text-2xl font-semibold text-zinc-900">Sign in through Koha</h1>
            @if (session('status'))
                <p class="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-800" role="status">{{ session('status') }}</p>
            @endif
            <p class="mt-3 text-sm leading-relaxed text-zinc-600">
                The Media Asset Management (MAM) system has no separate password. Sign in to the Koha staff interface, open a bibliographic record and choose
                <strong class="font-semibold text-zinc-800">Attach Media</strong>. You will be signed in here automatically.
            </p>
            @if (config('dam.koha.staff_url'))
                <a href="{{ config('dam.koha.staff_url') }}" class="mt-6 inline-flex w-full items-center justify-center rounded-xl bg-brand px-4 py-3 text-sm font-semibold text-white transition hover:bg-brand/90">Go to the Koha staff interface</a>
            @endif
        </div>
    </section>
</x-layouts::public>

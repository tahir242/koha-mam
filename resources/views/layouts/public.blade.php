<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('dam.institution.library_name') }}</title>
    @isset($description)<meta name="description" content="{{ $description }}">@endisset
    @if ($noindex ?? false)<meta name="robots" content="noindex">@endif
    @fonts
    @include('layouts.partials.brand')
    @vite(['resources/css/viewer.css'])
    {{ $head ?? '' }}
</head>
<body class="min-h-screen font-sans antialiased">
    <a href="#content" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:shadow">Skip to content</a>

    <header class="border-b border-black/5 bg-white/70 backdrop-blur">
        <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
            <a href="{{ route('home') }}" class="flex items-center gap-3">
                <span class="grid size-9 place-items-center rounded-lg bg-brand text-white">
                    <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 19.5V5a2 2 0 0 1 2-2h12v15H6a2 2 0 0 0-2 2Zm0 0A2 2 0 0 0 6 21h12" stroke-linejoin="round"/><path d="M9 7h6" stroke-linecap="round"/></svg>
                </span>
                <span class="leading-tight">
                    <span class="block font-serif text-[17px] font-semibold text-zinc-900">{{ config('dam.institution.library_name') }}</span>
                    <span class="hidden text-xs text-zinc-500 sm:block">{{ config('dam.institution.name') }}</span>
                </span>
            </a>
            {{ $nav ?? '' }}
        </div>
    </header>

    <main id="content">
        {{ $slot }}
    </main>

    <footer class="mt-16 border-t border-black/5">
        <div class="mx-auto flex max-w-6xl flex-col gap-2 px-4 py-8 text-xs text-zinc-500 sm:flex-row sm:justify-between sm:px-6">
            <p>&copy; {{ now()->year }} {{ config('dam.institution.name') }}</p>
            <p>Resources are provided for online viewing only.</p>
        </div>
    </footer>

    {{ $scripts ?? '' }}
</body>
</html>

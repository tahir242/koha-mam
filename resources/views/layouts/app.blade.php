<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('dam.institution.short_name') }} MAM</title>
    @fonts
    @include('layouts.partials.brand')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles(['nonce' => Vite::cspNonce()])
    @fluxAppearance(['nonce' => Vite::cspNonce()])
    {{-- The interface is light-only: without this, Flux follows the OS dark mode and renders white text on white cards. --}}
    <script nonce="{{ Vite::cspNonce() }}">window.Flux.applyAppearance('light');</script>
    <style nonce="{{ Vite::cspNonce() }}">:root { color-scheme: light; }</style>
</head>
<body class="h-full font-sans antialiased">
@php
    $user = auth()->user();
    $nav = [
        ['route' => 'dashboard', 'label' => 'Overview', 'icon' => 'squares-2x2', 'can' => 'media.view_dashboard'],
        ['route' => 'library', 'label' => 'Asset library', 'icon' => 'rectangle-stack', 'can' => 'media.view_dashboard'],
        ['route' => 'approvals', 'label' => 'Approvals', 'icon' => 'check-badge', 'can' => 'media.review'],
        ['route' => 'publications', 'label' => 'Koha publishing', 'icon' => 'arrow-path-rounded-square', 'can' => 'media.view_dashboard'],
    ];
    $admin = [
        ['route' => 'admin.integration', 'label' => 'Koha integration', 'icon' => 'link', 'can' => 'media.manage_settings'],
        ['route' => 'admin.storage', 'label' => 'Storage', 'icon' => 'server-stack', 'can' => 'media.manage_storage'],
        ['route' => 'admin.policies', 'label' => 'Publishing policies', 'icon' => 'scale', 'can' => 'media.manage_settings'],
        ['route' => 'admin.processing', 'label' => 'Processing', 'icon' => 'cpu-chip', 'can' => 'media.manage_settings'],
        ['route' => 'admin.staff', 'label' => 'Staff & roles', 'icon' => 'users', 'can' => 'media.manage_settings'],
        ['route' => 'admin.settings', 'label' => 'Settings', 'icon' => 'cog-6-tooth', 'can' => 'media.manage_settings'],
        ['route' => 'admin.audit', 'label' => 'Audit log', 'icon' => 'clipboard-document-list', 'can' => 'media.view_audit'],
    ];
    $pending = $user?->can('media.review') ? \App\Models\DigitalAsset::whereState('state', \App\States\Asset\PendingApproval::class)->count() : 0;
@endphp
<div x-data="{ open: false }" class="min-h-full">
    {{-- Sidebar --}}
    <aside class="fixed inset-y-0 left-0 z-40 flex w-72 -translate-x-full flex-col bg-brand text-white transition-transform duration-200 lg:translate-x-0"
           :class="open && 'translate-x-0'" aria-label="Primary">
        <div class="flex h-20 items-center px-6">
            <a href="{{ route('dashboard') }}" wire:navigate><x-brand-mark inverse /></a>
        </div>

        <nav class="flex-1 space-y-8 overflow-y-auto px-4 pb-6">
            <ul class="space-y-1">
                @foreach ($nav as $item)
                    @can($item['can'])
                        <li>
                            <a href="{{ route($item['route']) }}" wire:navigate
                               @class([
                                   'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition',
                                   'bg-white/12 text-white shadow-inner' => request()->routeIs($item['route']),
                                   'text-white/70 hover:bg-white/8 hover:text-white' => ! request()->routeIs($item['route']),
                               ])>
                                <flux:icon :name="$item['icon']" variant="outline" class="size-5 opacity-80" />
                                <span class="flex-1">{{ $item['label'] }}</span>
                                @if ($item['route'] === 'approvals' && $pending)
                                    <span class="rounded-full bg-brand-red px-2 py-0.5 text-[11px] font-semibold">{{ $pending }}</span>
                                @endif
                            </a>
                        </li>
                    @endcan
                @endforeach
            </ul>

            @if (collect($admin)->contains(fn ($i) => $user?->can($i['can'])))
                <div>
                    <p class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-[0.14em] text-white/40">Administration</p>
                    <ul class="space-y-1">
                        @foreach ($admin as $item)
                            @can($item['can'])
                                <li>
                                    <a href="{{ route($item['route']) }}" wire:navigate
                                       @class([
                                           'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition',
                                           'bg-white/12 text-white' => request()->routeIs($item['route']),
                                           'text-white/70 hover:bg-white/8 hover:text-white' => ! request()->routeIs($item['route']),
                                       ])>
                                        <flux:icon :name="$item['icon']" variant="outline" class="size-5 opacity-80" />
                                        {{ $item['label'] }}
                                    </a>
                                </li>
                            @endcan
                        @endforeach
                        @can('viewHorizon')
                            <li>
                                <a href="{{ url(config('horizon.path')) }}" class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-white/70 transition hover:bg-white/8 hover:text-white">
                                    <flux:icon name="queue-list" variant="outline" class="size-5 opacity-80" />
                                    Queue monitor
                                    <flux:icon name="arrow-up-right" variant="micro" class="ml-auto size-3.5 opacity-60" />
                                </a>
                            </li>
                        @endcan
                    </ul>
                </div>
            @endif
        </nav>

        @if (config('dam.koha.staff_url'))
            <div class="border-t border-white/10 p-4">
                <a href="{{ config('dam.koha.staff_url') }}/cgi-bin/koha/mainpage.pl" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-white/70 transition hover:bg-white/8 hover:text-white">
                    <flux:icon name="arrow-uturn-left" variant="outline" class="size-4" />
                    Back to Koha
                </a>
            </div>
        @endif
    </aside>

    <div x-show="open" x-cloak x-transition.opacity class="fixed inset-0 z-30 bg-zinc-900/40 lg:hidden" @click="open = false"></div>

    <div class="lg:pl-72">
        {{-- Top bar --}}
        <header class="sticky top-0 z-20 flex h-16 items-center gap-4 border-b border-zinc-200/70 bg-white/85 px-4 backdrop-blur sm:px-8">
            <button type="button" class="-ml-1 rounded-lg p-2 text-zinc-600 hover:bg-zinc-100 lg:hidden" @click="open = true" aria-label="Open navigation">
                <flux:icon name="bars-3" class="size-5" />
            </button>

            <form action="{{ route('library') }}" method="get" class="relative hidden max-w-md flex-1 sm:block" role="search">
                <label for="global-search" class="sr-only">Search assets</label>
                <flux:icon name="magnifying-glass" variant="mini" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
                <input id="global-search" name="search" type="search" placeholder="Search titles, record numbers…"
                       class="h-10 w-full rounded-xl border border-zinc-200 bg-zinc-50 pl-9 pr-3 text-sm placeholder:text-zinc-400 focus:border-brand focus:bg-white focus:outline-none focus:ring-4 focus:ring-brand/10">
            </form>

            <div class="ml-auto flex items-center gap-3">
                @if ($user)
                    <flux:dropdown position="bottom" align="end">
                        <button type="button" class="flex items-center gap-3 rounded-xl py-1.5 pl-1.5 pr-3 text-left transition hover:bg-zinc-100">
                            <span class="grid size-8 place-items-center rounded-lg bg-brand-100 text-xs font-semibold text-brand">{{ $user->initials() }}</span>
                            <span class="hidden leading-tight sm:block">
                                <span class="block text-sm font-medium text-zinc-900">{{ $user->name() }}</span>
                                <span class="block text-xs text-zinc-500">{{ $user->roleLabel() }}</span>
                            </span>
                            <flux:icon name="chevron-down" variant="micro" class="size-4 text-zinc-400" />
                        </button>
                        <flux:menu>
                            <div class="px-3 py-2 text-xs text-zinc-500">Signed in via Koha as <span class="font-medium text-zinc-700">{{ $user->koha_userid }}</span></div>
                            <flux:menu.separator />
                            <form method="post" action="{{ route('logout') }}">
                                @csrf
                                <flux:menu.item type="submit" icon="arrow-right-start-on-rectangle">Sign out</flux:menu.item>
                            </form>
                        </flux:menu>
                    </flux:dropdown>
                @endif
            </div>
        </header>

        @if (config('dam.sso.allow_insecure_http'))
            <div class="bg-brand-red px-4 py-2 text-center text-xs font-semibold text-white sm:px-8" role="alert">
                Test-only HTTP mode — sign-ins and data are not encrypted. Do not use with real material.
            </div>
        @endif

        <main class="px-4 py-8 sm:px-8">
            <div class="mx-auto max-w-7xl">
                {{ $slot }}
            </div>
        </main>
    </div>
</div>

@persist('toast')
    <flux:toast />
@endpersist

@livewireScripts(['nonce' => Vite::cspNonce()])
@fluxScripts(['nonce' => Vite::cspNonce()])
</body>
</html>

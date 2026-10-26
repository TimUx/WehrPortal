<!DOCTYPE html>
<html lang="de" class="h-full bg-fw-grey-bg">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#1B2645">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/images/wehrportal-icon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/images/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>{{ $title ? $title.' – '.($orgName ?? 'WehrPortal') : ($orgName ?? 'WehrPortal') }}</title>
    @include('partials.og-tags', ['preview' => app(\App\Support\LinkPreview::class)->forRequest(request())])
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full">
    @php
        $unreadFeedback = auth()->check() ? auth()->user()->feedbackThreads()->where('unread_for_user', true)->exists() : false;
        // "Admin" always leads to /admin: admins see everything there, module managers (Kleiderwarte …) their module's area
        $showAdmin = auth()->check() && auth()->user()->canAccessPanel(\Filament\Facades\Filament::getPanel('admin'));
    @endphp
    <div class="min-h-full flex flex-col">
        {{-- Top Header --}}
        @include('partials.impersonation-banner')
        <header class="bg-fw-navy text-white shadow-sm">
            <div class="w-full px-4 sm:px-6 lg:px-8 2xl:px-12">
                <div class="flex items-center justify-between h-11 sm:h-14">
                    <a href="{{ route('home') }}" class="flex min-w-0 flex-1 items-center gap-2.5 mr-3">
                        <img src="{{ $orgLogoUrl ?? asset('images/wehrportal-logo.svg') }}" alt="" class="h-7 sm:h-9 w-9 sm:w-12 shrink-0 object-contain">
                        <span class="font-semibold text-sm truncate" title="{{ $orgName ?? 'WehrPortal' }}">{{ $orgName ?? 'WehrPortal' }}</span>
                    </a>
                    @auth
                    <div class="flex shrink-0 items-center gap-2 sm:gap-6">
                    <nav class="hidden sm:flex items-center gap-6 text-sm">
                        <a href="{{ route('profile') }}" class="inline-flex items-center gap-2 hover:text-fw-grey-light {{ request()->routeIs('profile') ? 'font-semibold' : '' }}"><x-avatar :user="auth()->user()" size="h-7 w-7" text="text-[10px]" />Profil @if($unreadFeedback)<span class="ml-1 inline-block w-2 h-2 rounded-full bg-fw-red align-middle" title="Neue Antwort auf dein Feedback"></span>@endif</a>
                        @if($showAdmin)
                        <a href="{{ route('filament.admin.pages.dashboard') }}" class="hover:text-fw-grey-light">Admin</a>
                        @endif
                    </nav>
                    {{-- The digitaler Dienstausweis, for every active Kamerad once the Personalverwaltung switched it on --}}
                    @if(\Modules\Personalverwaltung\src\Services\PersonnelService::digitalIdFor(auth()->user()))
                        <a href="{{ route('personalverwaltung.digital-id') }}" title="Dienstausweis" aria-label="Dienstausweis"
                           class="inline-flex h-9 w-9 items-center justify-center rounded-lg hover:bg-white/10 {{ request()->routeIs('personalverwaltung.digital-id') ? 'bg-white/10' : '' }}">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="11" r="2"/><path stroke-linecap="round" d="M5.5 16c.5-1.5 1.6-2.2 3-2.2s2.5.7 3 2.2M14 10h4M14 13h4"/></svg>
                        </a>
                    @endif
                    <livewire:notifications.bell />
                    {{-- On the phone the text navigation is hidden: Profil and Admin are in the 3×3 menu --}}
                    <x-app-launcher :admin-url="$showAdmin ? route('filament.admin.pages.dashboard') : null" :unread-feedback="$unreadFeedback" />
                    </div>
                    @endauth
                </div>
            </div>
        </header>

        @foreach ($appContextViews ?? [] as $contextView)
            @include($contextView)
        @endforeach

        {{-- The Modulleiste: name, tabs, main action and Verwaltung of the module (or the Personalakte) --}}
        @auth<x-module-bar />@endauth

{{-- Main content --}}
        <main class="flex-1 w-full px-4 py-4 sm:px-6 lg:px-8 2xl:px-12">
            {{ $slot }}
        </main>

    </div>
    @auth
        @if(app(\App\Services\ModuleRegistry::class)->isActive('kleiderkammer'))
            @include('kleiderkammer::partials.scan-button', ['scanUrl' => \Modules\Kleiderkammer\src\Filament\Pages\ScanPage::getUrl()])
        @endif
    @endauth
    @auth
        @if(config('offline.enabled'))
            @include('offline::partials.status')
        @endif
    @endauth
    @livewireScripts
</body>
</html>

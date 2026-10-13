<!DOCTYPE html>
<html lang="de" class="h-full bg-fw-grey-bg">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#1B2645">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/images/icon-192.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>{{ $title ? $title.' – '.($orgName ?? 'openwehr') : ($orgName ?? 'openwehr') }}</title>
    @include('partials.og-tags', ['preview' => app(\App\Support\LinkPreview::class)->forRequest(request())])
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full">
    @php
        $openDecisions = auth()->user()?->canDecide() ? \Modules\Fahrzeugbuchung\src\Models\BookingDecision::pending()->count() : 0;
        $unreadFeedback = auth()->check() ? auth()->user()->feedbackThreads()->where('unread_for_user', true)->exists() : false;
        $inFahrzeugbuchung = request()->routeIs('fahrzeugbuchung.*');
        $inKleiderkammer = request()->routeIs('kleiderkammer.*');
        $inMaengel = request()->routeIs('maengelmanagement.*');
        $inAusruestung = request()->routeIs('ausruestung.*');
        $inSchichtplan = request()->routeIs('schichtplan.*');
        $inKalender = request()->routeIs('kalender.*');
        $inLehrgaenge = request()->routeIs('lehrgaenge.*');
        $adminRoute = match (true) {
            $inFahrzeugbuchung => 'filament.fahrzeugbuchung.pages.dashboard',
            $inKleiderkammer => 'filament.kleiderkammer.pages.dashboard',
            $inMaengel => 'filament.maengelmanagement.pages.dashboard',
            $inAusruestung => 'filament.ausruestung.pages.dashboard',
            default => 'filament.admin.pages.dashboard',
        };
        $showAdmin = auth()->check() && match (true) {
            $inKleiderkammer => auth()->user()->canManageKleiderkammer(),
            $inMaengel => auth()->user()->canManageMaengel(),
            $inAusruestung => auth()->user()->canManageAusruestung(),
            default => auth()->user()->is_admin,
        };
        // A module administered in the app by a role (e.g. Personalverwaltung): "Admin" leads to its admin page
        if ($moduleAdmin = app(\App\Services\ModuleRegistry::class)->adminRouteFor(request()->route()?->getName(), auth()->user())) {
            [$adminRoute, $showAdmin] = [$moduleAdmin, true];
        }
        // Phone: module tabs at the bottom only inside a module; Profil and Admin are in the 3×3 menu
        $canDecide = auth()->user()?->canDecide() ?? false;
        $bottomTabs = auth()->check() ? ($inFahrzeugbuchung ? 2 + (int) $canDecide : 0) + ($inMaengel ? 2 : 0) + ($inAusruestung ? 2 : 0) + ($inSchichtplan ? 1 : 0) + ($inKalender ? 1 : 0) + ($inLehrgaenge ? 1 : 0) : 0;
    @endphp
    <div class="min-h-full flex flex-col">
        {{-- Top Header --}}
        @include('partials.impersonation-banner')
        <header class="bg-fw-navy text-white shadow-sm">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-11 sm:h-14">
                    <a href="{{ route('home') }}" class="flex items-center gap-2.5">
                        <img src="{{ $orgLogoUrl ?? asset('images/openwehr-logo.svg') }}" alt="{{ $orgName ?? 'openwehr' }}" class="h-6 sm:h-8 w-auto brightness-0 invert">
                        <span class="font-semibold text-sm">{{ $orgName ?? 'openwehr' }}</span>
                    </a>
                    @auth
                    <div class="flex items-center gap-4 sm:gap-6">
                    <nav class="hidden sm:flex items-center gap-6 text-sm">
                        @if($inFahrzeugbuchung)
                        @if($openDecisions > 0)
                        <a href="{{ route('fahrzeugbuchung.decisions.index') }}"
                           class="px-3 py-1.5 rounded-lg bg-fw-red hover:bg-fw-red-dark font-semibold">
                            Entscheidung ({{ $openDecisions }})
                        </a>
                        @elseif(auth()->user()->canDecide())
                        <a href="{{ route('fahrzeugbuchung.decisions.index') }}" class="hover:text-fw-grey-light {{ request()->routeIs('fahrzeugbuchung.decisions.*') ? 'font-semibold' : '' }}">Entscheidungen</a>
                        @endif
                        <a href="{{ route('fahrzeugbuchung.calendar') }}" class="hover:text-fw-grey-light {{ request()->routeIs('fahrzeugbuchung.calendar') ? 'font-semibold' : '' }}">Kalender</a>
                        <a href="{{ route('fahrzeugbuchung.my-bookings') }}" class="hover:text-fw-grey-light {{ request()->routeIs('fahrzeugbuchung.my-bookings') ? 'font-semibold' : '' }}">Meine Buchungen</a>
                        @endif
                        @if($inKleiderkammer)
                        <a href="{{ route('kleiderkammer.index') }}" class="hover:text-fw-grey-light font-semibold">Meine Ausrüstung</a>
                        @endif
                        @if($inMaengel)
                        <a href="{{ route('maengelmanagement.index') }}" class="hover:text-fw-grey-light {{ request()->routeIs('maengelmanagement.index', 'maengelmanagement.show') ? 'font-semibold' : '' }}">Mängel</a>
                        <a href="{{ route('maengelmanagement.report') }}" class="hover:text-fw-grey-light {{ request()->routeIs('maengelmanagement.report') ? 'font-semibold' : '' }}">Mangel melden</a>
                        @endif
                        @if($inLehrgaenge)
                        <a href="{{ route('lehrgaenge.index') }}" class="hover:text-fw-grey-light {{ request()->routeIs('lehrgaenge.index') ? 'font-semibold' : '' }}">Meine Lehrgänge</a>
                        @if(auth()->user()->canManageLehrgaenge())
                        <a href="{{ route('lehrgaenge.registrations') }}" class="hover:text-fw-grey-light {{ request()->routeIs('lehrgaenge.registrations') ? 'font-semibold' : '' }}">Anmeldungen</a>
                        <a href="{{ route('lehrgaenge.courses') }}" class="hover:text-fw-grey-light {{ request()->routeIs('lehrgaenge.courses') ? 'font-semibold' : '' }}">Lehrgangsliste</a>
                        @endif
                        @if(auth()->user()->canViewLehrgaenge())
                        <a href="{{ route('lehrgaenge.plan') }}" class="hover:text-fw-grey-light {{ request()->routeIs('lehrgaenge.plan') ? 'font-semibold' : '' }}">Lehrgangsplan</a>
                        <a href="{{ route('lehrgaenge.members') }}" class="hover:text-fw-grey-light {{ request()->routeIs('lehrgaenge.members', 'lehrgaenge.member') ? 'font-semibold' : '' }}">Kameraden</a>
                        @endif
                        @endif
                        @if($inKalender)
                        <a href="{{ route('kalender.index') }}" class="hover:text-fw-grey-light {{ request()->routeIs('kalender.index', 'kalender.show') ? 'font-semibold' : '' }}">Kalender</a>
                        @if(auth()->user()->canManageKalender())
                        <a href="{{ route('kalender.create') }}" class="hover:text-fw-grey-light {{ request()->routeIs('kalender.create') ? 'font-semibold' : '' }}">Termin anlegen</a>
                        @endif
                        @endif
                        @if($inSchichtplan)
                        <a href="{{ route('schichtplan.index') }}" class="hover:text-fw-grey-light {{ request()->routeIs('schichtplan.index') ? 'font-semibold' : '' }}">Schichtpläne</a>
                        @if(auth()->user()->canManageSchichtplan())
                        <a href="{{ route('schichtplan.create') }}" class="hover:text-fw-grey-light {{ request()->routeIs('schichtplan.create') ? 'font-semibold' : '' }}">Neuer Plan</a>
                        @endif
                        @endif
                        @if($inAusruestung)
                        <a href="{{ route('ausruestung.equipment') }}" class="hover:text-fw-grey-light {{ request()->routeIs('ausruestung.equipment', 'ausruestung.show') ? 'font-semibold' : '' }}">Geräte</a>
                        <a href="{{ route('ausruestung.index') }}" class="hover:text-fw-grey-light {{ request()->routeIs('ausruestung.index') ? 'font-semibold' : '' }}">Meine Ausleihen</a>
                        @endif
                        <a href="{{ route('profile') }}" class="inline-flex items-center gap-2 hover:text-fw-grey-light {{ request()->routeIs('profile') ? 'font-semibold' : '' }}"><x-avatar :user="auth()->user()" size="h-7 w-7" text="text-[10px]" />Profil @if($unreadFeedback)<span class="ml-1 inline-block w-2 h-2 rounded-full bg-fw-red align-middle" title="Neue Antwort auf dein Feedback"></span>@endif</a>
                        @if($showAdmin)
                        <a href="{{ route($adminRoute) }}" class="hover:text-fw-grey-light">Admin</a>
                        @endif
                    </nav>
                    <livewire:notifications.bell />
                    {{-- On the phone the text navigation is hidden: Profil and Admin are in the 3×3 menu --}}
                    <x-app-launcher :admin-url="$showAdmin ? route($adminRoute) : null" :unread-feedback="$unreadFeedback" />
                    </div>
                    @endauth
                </div>
            </div>
        </header>

{{-- Main content --}}
        <main class="flex-1 {{ $bottomTabs > 0 ? 'pb-20 sm:pb-0' : '' }} px-4 sm:px-6 lg:px-8 py-4 max-w-7xl mx-auto w-full">
            {{ $slot }}
        </main>

        {{-- Mobile bottom tab bar --}}
        @auth
        @if($bottomTabs > 0)
        <nav class="sm:hidden fixed bottom-0 left-0 right-0 bg-white border-t border-gray-200 z-40">
            <div class="grid h-16" style="grid-template-columns: repeat({{ $bottomTabs }}, minmax(0, 1fr));">
                @if($inFahrzeugbuchung)
                <a href="{{ route('fahrzeugbuchung.calendar') }}"
                   class="flex flex-col items-center justify-center gap-1 text-xs {{ request()->routeIs('fahrzeugbuchung.calendar') ? 'text-fw-red' : 'text-gray-500' }}">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    Kalender
                </a>
                <a href="{{ route('fahrzeugbuchung.my-bookings') }}"
                   class="flex flex-col items-center justify-center gap-1 text-xs {{ request()->routeIs('fahrzeugbuchung.my-bookings') ? 'text-fw-red' : 'text-gray-500' }}">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                    Meine
                </a>
                @if($canDecide)
                <a href="{{ route('fahrzeugbuchung.decisions.index') }}"
                   class="relative flex flex-col items-center justify-center gap-1 text-xs {{ request()->routeIs('fahrzeugbuchung.decisions.*') ? 'text-fw-red' : 'text-gray-500' }}">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"/>
                    </svg>
                    Entscheid.
                    @if($openDecisions > 0)
                    <span class="absolute top-1.5 left-1/2 ml-2 min-w-4 h-4 px-1 rounded-full bg-fw-red text-white text-[10px] leading-4 text-center">{{ $openDecisions }}</span>
                    @endif
                </a>
                @endif
                @endif
                @if($inMaengel)
                <a href="{{ route('maengelmanagement.index') }}"
                   class="flex flex-col items-center justify-center gap-1 text-xs {{ request()->routeIs('maengelmanagement.index', 'maengelmanagement.show') ? 'text-fw-red' : 'text-gray-500' }}">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>
                    </svg>
                    Mängel
                </a>
                <a href="{{ route('maengelmanagement.report') }}"
                   class="flex flex-col items-center justify-center gap-1 text-xs {{ request()->routeIs('maengelmanagement.report') ? 'text-fw-red' : 'text-gray-500' }}">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    Melden
                </a>
                @endif
                @if($inAusruestung)
                <a href="{{ route('ausruestung.equipment') }}"
                   class="flex flex-col items-center justify-center gap-1 text-xs {{ request()->routeIs('ausruestung.equipment', 'ausruestung.show') ? 'text-fw-red' : 'text-gray-500' }}">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11.4 4.6a3 3 0 0 0-4 3.9L3 13l-.5 3.5L6 16l4.5-4.4a3 3 0 0 0 3.9-4l-2 2-1.9-.5-.5-1.9 2-2zM14 14l6 6M17 17l-2 2"/>
                    </svg>
                    Geräte
                </a>
                <a href="{{ route('ausruestung.index') }}"
                   class="flex flex-col items-center justify-center gap-1 text-xs {{ request()->routeIs('ausruestung.index') ? 'text-fw-red' : 'text-gray-500' }}">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/>
                    </svg>
                    Ausleihen
                </a>
                @endif
                @if($inSchichtplan)
                <a href="{{ route('schichtplan.index') }}"
                   class="flex flex-col items-center justify-center gap-1 text-xs {{ request()->routeIs('schichtplan.*') ? 'text-fw-red' : 'text-gray-500' }}">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 5h18M3 10h18M3 15h18M3 20h18M9 5v15M15 5v15"/>
                    </svg>
                    Schichten
                </a>
                @endif
                @if($inKalender)
                <a href="{{ route('kalender.index') }}"
                   class="flex flex-col items-center justify-center gap-1 text-xs {{ request()->routeIs('kalender.*') ? 'text-fw-red' : 'text-gray-500' }}">
                    <x-module-icon key="kalender" class="h-5 w-5" />
                    Kalender
                </a>
                @endif
                @if($inLehrgaenge)
                <a href="{{ route('lehrgaenge.index') }}"
                   class="flex flex-col items-center justify-center gap-1 text-xs {{ request()->routeIs('lehrgaenge.*') ? 'text-fw-red' : 'text-gray-500' }}">
                    <x-module-icon key="lehrgaenge" class="h-5 w-5" />
                    Lehrgänge
                </a>
                @endif
            </div>
        </nav>
        @endif
        @endauth
    </div>
    @livewireScripts
</body>
</html>

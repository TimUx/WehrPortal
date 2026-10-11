{{-- 3×3 dots like Microsoft 365: every module the Kamerad may use, also the ones hidden on the start page --}}
@php
    $launcherModules = app(\App\Services\ModuleOverview::class)->for(auth()->user());
@endphp
<div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false" x-on:click.outside="open = false">
    <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-haspopup="true" aria-label="Alle Module"
            title="Alle Module" class="flex h-9 w-9 items-center justify-center rounded-lg hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-white/40">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            @foreach([4, 12, 20] as $y)@foreach([4, 12, 20] as $x)<circle cx="{{ $x }}" cy="{{ $y }}" r="2" />@endforeach @endforeach
        </svg>
    </button>

    <div x-show="open" x-cloak x-transition.opacity
         class="absolute right-0 z-50 mt-2 w-72 max-w-[calc(100vw-2rem)] overflow-hidden rounded-2xl border border-gray-100 bg-white text-gray-900 shadow-xl">
        <a href="{{ route('home') }}" class="flex items-center gap-3 px-4 py-3 text-sm font-medium hover:bg-gray-50 {{ request()->routeIs('home') ? 'bg-gray-50' : '' }}">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-fw-navy/10">
                <svg class="h-4 w-4 text-fw-navy" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l9-9 9 9M5 10v10h5v-6h4v6h5V10"/></svg>
            </span>
            Startseite
        </a>

        @if(! empty($launcherModules))
            <p class="border-t border-gray-100 px-4 pt-3 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Module</p>
            <nav class="max-h-[70vh] overflow-y-auto pb-2">
                @foreach($launcherModules as $key => $module)
                    {{-- Module routes are named after their key, e.g. kalender.* --}}
                    @php($active = request()->routeIs($key.'.*'))
                    <a href="{{ $module['url'] }}" class="flex items-center gap-3 px-4 py-2 text-sm hover:bg-gray-50 {{ $active ? 'bg-gray-50 font-semibold text-fw-navy' : '' }}">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg {{ $active ? 'bg-fw-navy text-white' : 'bg-fw-navy/10 text-fw-navy' }}">
                            <x-module-icon :key="$key" class="h-4 w-4" />
                        </span>
                        <span class="min-w-0 truncate">{{ $module['name'] }}</span>
                    </a>
                @endforeach
            </nav>
        @else
            <p class="border-t border-gray-100 px-4 py-3 text-xs text-gray-500">Für dich ist noch kein Modul freigeschaltet.</p>
        @endif
    </div>
</div>

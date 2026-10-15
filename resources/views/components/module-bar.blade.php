{{-- The Modulleiste under the blue bar: where am I (module or Personalakte), its tabs (only those the
     Kamerad may open) and the Verwaltung. Same place and look in every module. The main action of a
     page ("Neuer Plan" …) sits next to the page title (x-page-action), not here. --}}
@php
    $user = auth()->user();
    $route = request()->route();
    $routeName = $route?->getName();
    if ($route && str_starts_with($route->uri(), 'personalakte')) {
        // The Personalakte: Profil and what the modules add, Nachweise and Stunden
        $items = [['label' => 'Profil', 'route' => 'profile'], ...app(\App\Services\PersonnelFileRegistry::class)->for($user)];
        if (app(\App\Services\CredentialRegistry::class)->cards($user)) {
            $items[] = ['label' => 'Nachweise', 'route' => 'credentials'];
        }
        if (app(\App\Services\HoursRegistry::class)->sources()) {
            $items[] = ['label' => 'Stunden', 'route' => 'hours'];
        }
        $bar = ['key' => null, 'name' => 'Meine Personalakte', 'url' => route('profile'), 'manage' => null,
            'tabs' => \App\Support\ModuleNav::markActive(array_map(fn ($i) => \App\Support\ModuleNav::tab($i['label'], $i['route']), $items), (string) $routeName)];
    } else {
        $bar = app(\App\Services\ModuleRegistry::class)->navigationFor($routeName, $user);
    }
@endphp
@if($bar)
    <div class="border-b border-gray-200 bg-white print:hidden" data-module-bar>
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex items-center gap-3 py-2 sm:min-h-12 sm:py-0">
                <a href="{{ $bar['url'] }}" class="flex shrink-0 items-center gap-2 text-sm font-semibold text-gray-900 hover:text-fw-navy">
                    @if($bar['key'])
                        <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-fw-navy text-white"><x-module-icon :key="$bar['key']" class="h-4 w-4" /></span>
                    @else
                        <x-avatar :user="$user" size="h-7 w-7" text="text-[10px]" />
                    @endif
                    <span>{{ $bar['name'] }}</span>
                </a>
                @if(count($bar['tabs']) > 1)
                    <nav class="hidden min-w-0 flex-1 items-stretch gap-1 self-stretch overflow-x-auto sm:flex" aria-label="{{ $bar['name'] }}">
                        @foreach($bar['tabs'] as $tab)
                            <a href="{{ $tab['url'] }}" @if($tab['active']) aria-current="page" @endif
                               class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap border-b-2 px-3 text-sm {{ $tab['active'] ? 'border-fw-red font-semibold text-gray-900' : 'border-transparent text-gray-600 hover:border-gray-300 hover:text-gray-900' }}">
                                {{ $tab['label'] }}
                                @if($tab['badge'])<span class="min-w-5 rounded-full bg-fw-red px-1.5 text-center text-xs font-semibold leading-5 text-white">{{ $tab['badge'] }}</span>@endif
                            </a>
                        @endforeach
                    </nav>
                @else
                    <span class="hidden flex-1 sm:block"></span>
                @endif
                <div class="ml-auto flex shrink-0 items-center gap-2 sm:ml-0">
                    @if($bar['manage'])
                        <a href="{{ $bar['manage'] }}" title="Verwaltung" aria-label="Verwaltung" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm text-gray-700 hover:border-fw-navy hover:text-fw-navy">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.3 4.3c.4-1.8 2.9-1.8 3.4 0a1.7 1.7 0 0 0 2.6 1c1.5-.9 3.3.8 2.4 2.4a1.7 1.7 0 0 0 1 2.6c1.8.4 1.8 2.9 0 3.4a1.7 1.7 0 0 0-1 2.6c.9 1.5-.8 3.3-2.4 2.4a1.7 1.7 0 0 0-2.6 1c-.4 1.8-2.9 1.8-3.4 0a1.7 1.7 0 0 0-2.6-1c-1.5.9-3.3-.8-2.4-2.4a1.7 1.7 0 0 0-1-2.6c-1.8-.4-1.8-2.9 0-3.4a1.7 1.7 0 0 0 1-2.6c-.9-1.5.8-3.3 2.4-2.4a1.7 1.7 0 0 0 2.6-1z"/><circle cx="12" cy="12" r="3"/></svg>
                            <span class="hidden sm:inline">Verwaltung</span>
                        </a>
                    @endif
                </div>
            </div>
            {{-- Phone: the tabs in one row to swipe --}}
            @if(count($bar['tabs']) > 1)
                <nav class="-mx-4 flex gap-1.5 overflow-x-auto px-4 pb-2 sm:hidden" aria-label="{{ $bar['name'] }}">
                    @foreach($bar['tabs'] as $tab)
                        <a href="{{ $tab['url'] }}" @if($tab['active']) aria-current="page" @endif
                           class="inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded-full px-3 py-1.5 text-sm {{ $tab['active'] ? 'bg-fw-navy text-white' : 'border border-gray-200 bg-white text-gray-700' }}">
                            {{ $tab['label'] }}
                            @if($tab['badge'])<span class="min-w-5 rounded-full bg-fw-red px-1.5 text-center text-xs font-semibold leading-5 text-white">{{ $tab['badge'] }}</span>@endif
                        </a>
                    @endforeach
                </nav>
            @endif
        </div>
    </div>
@endif

{{-- Menu of the Personalakte: Profil and what the modules add (e.g. Einsätze) --}}
@php($items = app(\App\Services\PersonnelFileRegistry::class)->for(auth()->user()))
@php($items = app(\App\Services\CredentialRegistry::class)->cards(auth()->user()) ? [...$items, ['label' => 'Nachweise', 'route' => 'credentials']] : $items)
@php($items = app(\App\Services\HoursRegistry::class)->sources() ? [...$items, ['label' => 'Stunden', 'route' => 'hours']] : $items)
@if($items)
    <nav class="flex flex-wrap gap-2 text-sm" aria-label="Personalakte">
        @foreach([['label' => 'Profil', 'route' => 'profile'], ...$items] as $item)
            <a href="{{ route($item['route']) }}" class="rounded-full px-3 py-1.5 {{ request()->routeIs($item['route']) ? 'bg-fw-navy text-white' : 'bg-white border border-gray-200 text-gray-700 hover:border-fw-navy' }}">{{ $item['label'] }}</a>
        @endforeach
    </nav>
@endif

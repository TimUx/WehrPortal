{{-- Tabs of the Fahrtenbuch --}}
@php($manage = \Modules\Fahrtenbuch\src\Services\LogbookService::canManage(auth()->user()))
<nav class="mb-4 flex flex-wrap gap-2 text-sm">
    @foreach(array_filter(['fahrtenbuch.index' => 'Fahrzeuge', 'fahrtenbuch.fuel' => 'Tanken', 'fahrtenbuch.statistics' => $manage ? 'Auswertung' : null]) as $route => $label)
        <a href="{{ route($route) }}" class="rounded-full px-3 py-1.5 {{ request()->routeIs($route) ? 'bg-fw-navy text-white' : 'bg-white border border-gray-200 text-gray-700 hover:border-fw-navy' }}">{{ $label }}</a>
    @endforeach
</nav>

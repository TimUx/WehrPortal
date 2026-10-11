{{-- Tabs: everyone sees the due Wartungen and the protocols; Gerätewarte more --}}
@php($manage = \Modules\Wartung\src\Services\WartungService::canManage(auth()->user()))
<nav class="mb-4 flex flex-wrap gap-2 text-sm">
    @foreach(array_filter(['wartung.index' => 'Fällige Wartungen', 'wartung.protocols' => 'Prüfprotokolle', 'wartung.templates' => $manage ? 'Vorlagen' : null,
        'wartung.providers' => $manage ? 'Fachfirmen' : null, 'wartung.inspectors' => $manage ? 'Prüfer' : null]) as $route => $label)
        <a href="{{ route($route) }}" class="rounded-full px-3 py-1.5 {{ request()->routeIs($route) ? 'bg-fw-navy text-white' : 'bg-white border border-gray-200 text-gray-700 hover:border-fw-navy' }}">{{ $label }}</a>
    @endforeach
</nav>

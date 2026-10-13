<nav class="mb-4 flex flex-wrap gap-2 text-sm">
    @foreach(array_filter([
        'personalverwaltung.index' => 'Kameraden',
        'personalverwaltung.honors' => 'Ehrungen',
        'personalverwaltung.attendance' => \Modules\Personalverwaltung\src\Services\PersonnelService::attendanceAvailable() ? 'Dienstbeteiligung' : null,
        'personalverwaltung.settings' => 'Einstellungen',
        'personalverwaltung.access' => auth()->user()->is_admin ? 'Zugriff' : null,
    ]) as $route => $label)
        <a href="{{ route($route) }}" class="rounded-full px-3 py-1.5 {{ request()->routeIs($route, $route === 'personalverwaltung.index' ? 'personalverwaltung.member' : $route) ? 'bg-fw-navy text-white' : 'bg-white border border-gray-200 text-gray-700 hover:border-fw-navy' }}">{{ $label }}</a>
    @endforeach
</nav>

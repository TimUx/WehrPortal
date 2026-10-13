@php($manage = \Modules\Personalverwaltung\src\Services\PersonnelService::canManage(auth()->user()))
<nav class="mb-4 flex flex-wrap gap-2 text-sm">
    @foreach(array_filter([
        'personalverwaltung.index' => 'Kameraden',
        'personalverwaltung.new' => $manage ? 'Neue Mitglieder' : null,
        'personalverwaltung.requests' => $manage ? 'Änderungswünsche' : null,
        'personalverwaltung.honors' => $manage ? 'Ehrungen' : null,
        'personalverwaltung.reports' => 'Auswertungen',
        'personalverwaltung.hours' => app(\App\Services\HoursRegistry::class)->sources() ? 'Stunden' : null,
        'personalverwaltung.attendance' => \Modules\Personalverwaltung\src\Services\PersonnelService::attendanceAvailable() ? 'Dienstbeteiligung' : null,
        'personalverwaltung.settings' => $manage ? 'Einstellungen' : null,
        'personalverwaltung.access' => auth()->user()->is_admin ? 'Zugriff' : null,
    ]) as $route => $label)
        <a href="{{ route($route) }}" class="rounded-full px-3 py-1.5 {{ request()->routeIs($route, $route === 'personalverwaltung.index' ? 'personalverwaltung.member' : $route) ? 'bg-fw-navy text-white' : 'bg-white border border-gray-200 text-gray-700 hover:border-fw-navy' }}">{{ $label }}</a>
    @endforeach
</nav>

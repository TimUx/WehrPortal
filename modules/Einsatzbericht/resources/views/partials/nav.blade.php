{{-- Tabs for Einsatzleiter and Wehrführung --}}
@php($release = \Modules\Einsatzbericht\src\Services\ReportService::canRelease(auth()->user()))
<nav class="mb-4 flex flex-wrap gap-2 text-sm">
    @foreach(array_filter(['einsatzbericht.index' => 'Einsätze', 'einsatzbericht.statistics' => $release ? 'Jahresstatistik' : null, 'einsatzbericht.members' => $release ? 'Kameraden' : null, 'einsatzbericht.settings' => $release ? 'Einstellungen' : null, 'einsatzbericht.materials' => auth()->user()->can('einsatzbericht-materials') ? 'Material' : null, 'einsatzbericht.personnel-file' => 'Meine Einsätze']) as $route => $label)
        <a href="{{ route($route) }}" class="rounded-full px-3 py-1.5 {{ request()->routeIs($route) ? 'bg-fw-navy text-white' : 'bg-white border border-gray-200 text-gray-700 hover:border-fw-navy' }}">{{ $label }}</a>
    @endforeach
</nav>

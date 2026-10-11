<x-layouts.app title="Einsätze">
    <div class="mx-auto max-w-2xl space-y-4">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Laufende Einsätze</h1>
            <p class="text-sm text-gray-500">Hier schickst du während eines Einsatzes Lagemeldungen mit Foto. Deine eigenen Einsätze stehen in der <a href="{{ route('einsatzbericht.personnel-file') }}" class="underline">Personalakte</a>.</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100">
            @forelse($running as $report)
                <a href="{{ route('einsatzbericht.situation', $report) }}" class="block px-4 py-3 hover:bg-gray-50">
                    <p class="font-semibold text-gray-900">{{ $report->keyword ?: 'Einsatz '.$report->number }}</p>
                    <p class="text-sm text-gray-600">{{ $report->alarmed_at?->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}{{ $report->address ? ' · '.$report->address : '' }}</p>
                    <p class="mt-1 text-sm font-semibold text-fw-red">Lagemeldung schreiben →</p>
                </a>
            @empty
                <p class="px-4 py-6 text-sm text-gray-500">Gerade läuft kein Einsatz.</p>
            @endforelse
        </div>
    </div>
</x-layouts.app>

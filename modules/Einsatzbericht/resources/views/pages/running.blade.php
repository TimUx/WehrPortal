<x-layouts.app title="Einsätze">
    <div class="mx-auto max-w-2xl space-y-4">
        @if(session('status'))
            <p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ session('status') }}</p>
        @endif
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-gray-900">Einsatzbericht schreiben</h1>
                <p class="text-sm text-gray-500">Du warst im Einsatz und schreibst den Bericht? Lege ihn an und fülle ihn aus. Mit „Abschließen“ geht er an die Wehrführung; danach kannst du ihn nicht mehr öffnen.</p>
            </div>
            <form method="POST" action="{{ route('einsatzbericht.create') }}">
                @csrf
                <button type="submit" class="inline-flex items-center rounded-lg bg-fw-red px-4 py-2.5 text-sm font-semibold text-white hover:bg-fw-red-dark">+ Neuer Einsatz</button>
            </form>
        </div>
        @if($unclaimed->isNotEmpty())
            <div class="bg-white rounded-2xl border border-amber-200 shadow-sm divide-y divide-gray-100">
                <p class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-amber-700">Aus der Alarmierung – den Bericht schreibt noch niemand</p>
                @foreach($unclaimed as $report)
                    <form method="POST" action="{{ route('einsatzbericht.claim', $report) }}" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                        @csrf
                        <div>
                            <p class="font-semibold text-gray-900">{{ $report->keyword ?: 'Einsatz '.$report->number }}</p>
                            <p class="text-sm text-gray-600">{{ $report->alarmed_at?->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}{{ $report->address ? ' · '.$report->address : '' }}</p>
                        </div>
                        <button type="submit" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Bericht übernehmen</button>
                    </form>
                @endforeach
            </div>
        @endif
        @if($own->isNotEmpty())
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100">
                @foreach($own as $report)
                    <a href="{{ route('einsatzbericht.show', $report) }}" class="block px-4 py-3 hover:bg-gray-50">
                        <p class="font-semibold text-gray-900">{{ $report->keyword ?: 'Einsatz '.$report->number }} <span class="text-xs font-normal text-gray-500">· {{ $report->status === 'draft' ? 'Entwurf' : 'Aktuell' }}</span></p>
                        <p class="text-sm text-gray-600">{{ $report->alarmed_at?->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}{{ $report->address ? ' · '.$report->address : '' }}</p>
                        <p class="mt-1 text-sm font-semibold text-fw-navy">Bericht weiterschreiben →</p>
                    </a>
                @endforeach
            </div>
        @endif
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

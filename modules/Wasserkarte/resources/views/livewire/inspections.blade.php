<div class="space-y-4">
    <h1 class="text-xl font-bold text-gray-900">Prüfungen & Import</h1>

    <section class="space-y-2">
        <p class="text-sm font-semibold text-gray-900">Offene Mängel ({{ $defects->count() }})</p>
        <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
            @forelse($defects as $d)
                <a href="{{ route('wasserkarte.source', $d->source_id) }}" class="block p-3 text-sm hover:bg-gray-50" wire:key="d-{{ $d->id }}">
                    <span class="block text-gray-900">{{ $d->source->label() }}{{ $d->source->location ? ' · '.$d->source->location : '' }}</span>
                    <span class="text-xs text-red-700">{{ $d->created_at->setTimezone('Europe/Berlin')->format('d.m.Y') }} · {{ $d->reporter?->name }}: {{ $d->description }}</span>
                </a>
            @empty
                <p class="p-3 text-sm text-gray-500">Keine offenen Mängel.</p>
            @endforelse
        </div>
    </section>

    <section class="space-y-2">
        <p class="text-sm font-semibold text-gray-900">Prüfungen fällig (überfällig und nächste 30 Tage)</p>
        @if($never)<p class="text-xs text-amber-800">{{ $never }} Hydrant{{ $never === 1 ? '' : 'en' }} ohne eingetragene Prüfung.</p>@endif
        <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
            @forelse($due as $s)
                <a href="{{ route('wasserkarte.source', $s) }}" class="flex items-center justify-between gap-3 p-3 text-sm hover:bg-gray-50" wire:key="s-{{ $s->id }}">
                    <span>{{ $s->label() }}{{ $s->location ? ' · '.$s->location : '' }}</span>
                    <span class="shrink-0 text-xs font-semibold {{ $s->inspectionOverdue() ? 'text-red-700' : 'text-amber-800' }}">{{ $s->inspectionOverdue() ? 'überfällig seit' : 'fällig' }} {{ $s->next_inspection_on->format('d.m.Y') }}</span>
                </a>
            @empty
                <p class="p-3 text-sm text-gray-500">Nichts fällig.</p>
            @endforelse
        </div>
    </section>

    <section class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">Liste des Wasserversorgers einlesen (CSV)</p>
        <p class="text-xs text-gray-500">Kopfzeile mit mindestens <code>Breitengrad</code>/<code>lat</code> und <code>Längengrad</code>/<code>lng</code>; erkannt werden außerdem Nummer, Art (z. B. „Unterflur“, „Überflur“, „Teich“), Lage, Durchfluss (l/min), Druck, DN, Inhalt (m³) und Betreiber. Semikolon oder Komma. Gibt es eine Nummer schon, wird die Quelle aktualisiert statt doppelt angelegt.</p>
        <input type="file" wire:model="csv" accept=".csv,text/csv,text/plain" class="text-sm">
        @error('csv')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        <button type="button" wire:click="import" wire:loading.attr="disabled" class="rounded-lg bg-fw-navy px-3 py-2 text-sm font-semibold text-white">Einlesen</button>
        @if($importResult)
            <p class="text-sm text-green-800">{{ $importResult['created'] }} neu, {{ $importResult['updated'] }} aktualisiert{{ count($importResult['skipped']) ? ', '.count($importResult['skipped']).' übersprungen' : '' }}.</p>
            @foreach($importResult['skipped'] as $line)<p class="text-xs text-amber-800">{{ $line }}</p>@endforeach
        @endif
    </section>
</div>

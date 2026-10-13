<div class="mx-auto max-w-4xl space-y-4">
    <div>
        <h1 class="text-xl font-bold text-gray-900">Lehrgangsergebnisse importieren</h1>
        <p class="text-sm text-gray-500">Für Ergebnislisten externer Träger – Kreisausbildung, Landesfeuerwehrschule, Export aus FireCircle oder einer anderen Lehrgangsverwaltung – statt jedes Ergebnis einzeln nachzutragen. CSV mit Kopfzeile (Semikolon oder Komma): <b>Lehrgang</b>, <b>Datum</b> (Abschluss, TT.MM.JJJJ) und <b>E-Mail</b> oder <b>Name</b> (auch „Nachname, Vorname“ oder Spalten Vorname/Nachname); optional <b>Ergebnis</b> – „nicht bestanden“ wird übersprungen. Zuerst siehst du, was passiert.</p>
    </div>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <input type="file" wire:model="file" accept=".csv,text/csv,text/plain" class="text-sm" aria-label="CSV-Datei">
        @foreach(['file', 'import'] as $f)@error($f)<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror @endforeach
    </div>
    @if($rows->isNotEmpty())
        <div class="overflow-x-auto rounded-2xl border border-gray-100 bg-white shadow-sm">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="p-2">Zeile</th><th class="p-2">Kamerad</th><th class="p-2">Lehrgang</th><th class="p-2">Datum</th><th class="p-2"></th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($rows as $r)
                        <tr><td class="p-2 text-gray-500">{{ $r['line'] }}</td><td class="p-2">{{ $r['name'] }}</td><td class="p-2">{{ $r['course'] }}</td>
                            <td class="p-2">{{ $r['date'] ? \Illuminate\Support\Carbon::parse($r['date'])->format('d.m.Y') : '–' }}</td>
                            <td class="p-2 text-xs {{ in_array($r['action'], ['new', 'refresh'], true) ? 'font-semibold text-green-700' : 'text-gray-500' }}">{{ $r['label'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @php($take = $rows->whereIn('action', ['new', 'refresh'])->count())
        <button type="button" wire:click="apply" @disabled(! $take) class="rounded-lg bg-fw-navy px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{{ $take }} übernehmen</button>
    @endif
</div>

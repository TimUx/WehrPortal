<div class="mx-auto max-w-5xl space-y-5">
    <div>
        <h1 class="text-xl font-bold text-gray-900">Gefahrstoffdaten importieren</h1>
        <p class="mt-1 text-sm text-gray-600">Importiere nur Daten, die du verwenden und in Openwehr speichern darfst. Der Import überschreibt vorhandene UN-Nummern, löscht aber keine nicht enthaltenen Datensätze.</p>
    </div>

    @if($result)
        <p class="rounded-xl bg-green-50 p-3 text-sm text-green-900">{{ $result['created'] }} neu hinzugefügt, {{ $result['updated'] }} aktualisiert.</p>
    @endif

    <section class="space-y-4 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm text-gray-700">CSV (Komma oder Semikolon) oder JSON. Pflichtfelder: UN-Nummer (vier Ziffern) und Name. Unterstützte optionale Felder: Synonyme, Beschreibung, ADR-Klasse, Verpackungsgruppe, Gefahrnummer, Gefahrzettel, GHS-Piktogramme, Gefahren, Erste Hilfe, Brandbekämpfung und Freisetzung. JSON erwartet ein Array oder <code>{"materials":[...]}</code>; Listen können Arrays oder in CSV mit <code>|</code> getrennte Werte sein.</p>
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="block text-sm font-medium text-gray-700">Quelle *
                <input type="text" wire:model="sourceName" maxlength="255" class="mt-1 w-full rounded-lg border-gray-300 text-sm" placeholder="z. B. Datenanbieter / Dokument">
            </label>
            <label class="block text-sm font-medium text-gray-700">Datenstand / Version
                <input type="text" wire:model="sourceVersion" maxlength="100" class="mt-1 w-full rounded-lg border-gray-300 text-sm" placeholder="z. B. Ausgabe 2026-01">
            </label>
            <label class="block text-sm font-medium text-gray-700 sm:col-span-2">Quellenverweis (optional)
                <input type="text" wire:model="sourceReference" maxlength="2000" class="mt-1 w-full rounded-lg border-gray-300 text-sm" placeholder="Dokumenttitel, URL oder Lizenzhinweis">
            </label>
        </div>
        <input type="file" wire:model="file" accept=".csv,.json,.txt,application/json,text/csv" class="text-sm" aria-label="CSV- oder JSON-Datei">
        <div wire:loading wire:target="file" class="text-sm text-gray-500">Datei wird hochgeladen …</div>
        @foreach(['file', 'sourceName', 'import', 'sourceVersion', 'sourceReference'] as $field)
            @error($field)<p class="text-sm text-red-700">{{ $message }}</p>@enderror
        @endforeach
    </section>

    @if($rows->isNotEmpty())
        <div class="flex flex-wrap items-center gap-2 text-xs">
            <span class="rounded-full bg-green-50 px-3 py-1 text-green-800">{{ $rows->whereIn('action', ['new', 'update'])->count() }} gültig</span>
            <span class="rounded-full bg-red-50 px-3 py-1 text-red-800">{{ $rows->where('action', 'invalid')->count() }} fehlerhaft</span>
        </div>
        <div class="overflow-x-auto rounded-2xl border border-gray-100 bg-white shadow-sm">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-gray-50 text-xs text-gray-600"><tr><th class="p-3">Zeile</th><th class="p-3">UN</th><th class="p-3">Stoff</th><th class="p-3">Aktion / Fehler</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($rows as $row)
                        <tr wire:key="import-row-{{ $row['line'] }}">
                            <td class="p-3 text-gray-500">{{ $row['line'] }}</td><td class="p-3">{{ $row['un_number'] ?: '–' }}</td><td class="p-3">{{ $row['name'] ?: '–' }}</td>
                            <td class="p-3 text-xs {{ $row['action'] === 'invalid' ? 'font-semibold text-red-700' : 'text-green-800' }}">{{ $row['label'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <button type="button" wire:click="import" wire:loading.attr="disabled"
            @disabled($rows->where('action', 'invalid')->isNotEmpty())
            class="rounded-lg bg-fw-navy px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
            {{ $rows->whereIn('action', ['new', 'update'])->count() }} Datensätze übernehmen
        </button>
    @endif
</div>

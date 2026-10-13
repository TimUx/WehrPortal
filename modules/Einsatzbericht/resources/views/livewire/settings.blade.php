@php($input = 'mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
<div class="mx-auto max-w-3xl space-y-6">
    <h1 class="text-xl font-bold text-gray-900">Einstellungen Einsatzbericht</h1>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif

    {{-- Mandatory fields --}}
    <section class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm space-y-2">
        <h2 class="text-base font-semibold text-gray-900">Pflichtfelder</h2>
        <p class="text-sm text-gray-500">Was ausgefüllt sein muss, bevor der Einsatzleiter einen Bericht abschließen kann. Im Bericht sind diese Felder mit * markiert.</p>
        <form wire:submit="save" class="space-y-1">
            @foreach($fields as $value => $label)
                <label class="flex items-center gap-3 py-1 text-sm text-gray-800"><input type="checkbox" wire:model="required" value="{{ $value }}" class="h-5 w-5 rounded border-gray-300 text-fw-red focus:ring-fw-red"> {{ $label }}</label>
            @endforeach
            <button type="submit" class="mt-2 rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white">Pflichtfelder speichern</button>
        </form>
    </section>

    {{-- What counts in Jahresstatistik and Jahresbericht --}}
    <section class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm space-y-2">
        <h2 class="text-base font-semibold text-gray-900">Statistik und Jahresbericht</h2>
        <form wire:submit="saveCounting" class="space-y-2">
            <label class="flex items-center gap-3 text-sm text-gray-800"><input type="checkbox" wire:model="releasedOnly" class="h-5 w-5 rounded border-gray-300 text-fw-red focus:ring-fw-red"> Nur freigegebene Berichte zählen</label>
            <p class="text-sm text-gray-500">Empfohlen: Erst nach der Prüfung durch die Wehrführung fließt ein Einsatz in Jahresstatistik, Verbrauch und Jahresbericht ein – so bleiben die Zahlen sauber. Ausgeschaltet zählen auch abgeschlossene, noch nicht freigegebene Berichte.</p>
            <button type="submit" class="rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white">Speichern</button>
        </form>
    </section>

    {{-- DIVERA 24/7 --}}
    <section class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm space-y-4">
        <div>
            <h2 class="text-base font-semibold text-gray-900">DIVERA 24/7 (Webhook)</h2>
            <p class="text-sm text-gray-500">Bei jeder Alarmierung schickt DIVERA die Einsatzdaten an die Adresse unten. Daraus entsteht ein aktueller Einsatz mit Stichwort, Meldung, Adresse und Alarmzeit; Besatzung, Ausrück- und Endzeiten trägt der Einsatzleiter nach. Nur mit DIVERA-Lizenz ALARM oder PRO.</p>
        </div>

        <form wire:submit="saveDivera" class="space-y-4">
            <label class="flex items-center gap-3 text-sm font-medium text-gray-800"><input type="checkbox" wire:model="diveraEnabled" class="h-5 w-5 rounded border-gray-300 text-fw-red focus:ring-fw-red"> Webhook eingeschaltet</label>

            @if($url)
                <div x-data="{ copied: false }">
                    <p class="text-sm font-medium text-gray-700">Adresse für DIVERA</p>
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        <code class="min-w-0 flex-1 break-all rounded-lg bg-gray-100 px-3 py-2 text-xs text-gray-800">{{ $url }}</code>
                        <button type="button" x-on:click="navigator.clipboard.writeText(@js($url)); copied = true; setTimeout(() => copied = false, 2000)" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700" x-text="copied ? 'Kopiert' : 'Kopieren'">Kopieren</button>
                        <button type="button" wire:click="regenerateKey" wire:confirm="Neue Adresse erzeugen? Die bisherige funktioniert danach nicht mehr und muss in DIVERA ersetzt werden." class="text-sm text-red-600 underline">Neu erzeugen</button>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">Die Adresse ist das Passwort – nicht weitergeben. In DIVERA: Verwaltung der Einheit → Einstellungen → Schnittstellen → Datenübergabe → Webhooks; Methode POST, Format JSON, Inhalt „Textinformationen“.</p>
                </div>
            @else
                <p class="text-sm text-gray-600">Beim Einschalten wird eine geheime Adresse erzeugt.</p>
            @endif

            <div>
                <div class="flex items-center justify-between gap-2">
                    <p class="text-sm font-medium text-gray-700">Feldzuordnung</p>
                    <button type="button" wire:click="resetMapping" class="text-xs text-gray-500 underline">Vorgabe</button>
                </div>
                <p class="text-xs text-gray-500">Welche Felder des Requests in den Bericht kommen. Mehrere Namen mit Komma (der erste vorhandene zählt), verschachtelte mit Punkt, z. B. <code>data.title</code>. Die Namen stehen unten beim letzten Request.</p>
                <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-3">
                    @foreach(\Modules\Einsatzbericht\src\Services\DiveraWebhook::MAPPING_LABELS as $field => $label)
                        <label class="block text-sm text-gray-700">{{ $label }}<input type="text" wire:model="mapping.{{ $field }}" maxlength="255" class="{{ $input }} font-mono"></label>
                    @endforeach
                </div>
            </div>

            <label class="block text-sm text-gray-700">Kein Einsatz bei diesen Stichwörtern (eins pro Zeile, Teil von Stichwort oder Meldung genügt)
                <textarea wire:model="filters" rows="3" class="{{ $input }}"></textarea>
            </label>

            <button type="submit" class="rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white">DIVERA speichern</button>
        </form>

        {{-- Test alarm without DIVERA --}}
        <form wire:submit="sendTestAlarm" class="rounded-xl border border-dashed border-gray-300 bg-gray-50 p-4 space-y-3">
            <div>
                <p class="text-sm font-semibold text-gray-900">Test-Alarm</p>
                <p class="text-xs text-gray-500">Schickt einen Alarm wie von DIVERA – mit den Feldnamen der Zuordnung oben – durch denselben Ablauf: Rohdaten, Filter, Duplikate, Einsatz, Geocoding. Ohne „Probealarm“ entsteht ein echter Einsatz, der danach gelöscht werden kann. Ob DIVERA dieselben Feldnamen schickt, zeigt erst ein echter Alarm.</p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label class="block text-sm text-gray-700">Stichwort<input type="text" wire:model="test.keyword" maxlength="150" class="{{ $input }}"></label>
                <label class="block text-sm text-gray-700">Adresse<input type="text" wire:model="test.address" maxlength="255" placeholder="z. B. Dorfstraße 1, Musterstadt" class="{{ $input }}"></label>
            </div>
            <label class="block text-sm text-gray-700">Meldung<textarea wire:model="test.message" rows="2" class="{{ $input }}"></textarea></label>
            <div class="grid grid-cols-2 gap-3">
                <label class="block text-sm text-gray-700">Breitengrad (optional)<input type="text" inputmode="decimal" wire:model="test.latitude" placeholder="51.1657" class="{{ $input }}"></label>
                <label class="block text-sm text-gray-700">Längengrad (optional)<input type="text" inputmode="decimal" wire:model="test.longitude" placeholder="10.4515" class="{{ $input }}"></label>
            </div>
            @foreach(['test.keyword', 'test.latitude', 'test.longitude'] as $field)@error($field)<p class="text-sm text-red-600">{{ $message }}</p>@enderror @endforeach
            <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="test.probe" class="h-5 w-5 rounded border-gray-300 text-fw-red focus:ring-fw-red"> Als Probealarm kennzeichnen (wird gefiltert, kein Einsatz)</label>
            <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="test.via_url" class="h-5 w-5 rounded border-gray-300 text-fw-red focus:ring-fw-red"> Über die öffentliche Adresse senden (prüft auch Erreichbarkeit und Schlüssel)</label>
            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-fw-navy px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Test-Alarm senden</button>
                @if($testResult)<p class="text-sm {{ str_contains($testResult, 'fehlgeschlagen') || str_contains($testResult, 'Fehler') ? 'text-red-700' : 'text-green-800' }}">{{ $testResult }}</p>@endif
            </div>
        </form>

        <div>
            <p class="text-sm font-medium text-gray-700">Letzte Requests</p>
            <p class="text-xs text-gray-500">Jeder Request wird unverändert gespeichert und nach {{ \Modules\Einsatzbericht\src\Models\DiveraRequest::KEEP_DAYS }} Tagen gelöscht.</p>
            @if($latestFields)
                <details class="mt-2 rounded-lg bg-gray-50 p-3 text-xs" open>
                    <summary class="cursor-pointer font-semibold text-gray-700">Felder im letzten Request</summary>
                    <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5">
                        @foreach(array_slice($latestFields, 0, 60, true) as $name => $value)
                            <dt class="font-mono text-gray-700">{{ $name }}</dt><dd class="break-all text-gray-600">{{ is_scalar($value) ? \Illuminate\Support\Str::limit((string) $value, 120) : '…' }}</dd>
                        @endforeach
                    </dl>
                </details>
            @endif
            <ul class="mt-2 divide-y divide-gray-100 text-sm">
                @forelse($requests as $entry)
                    <li class="py-2" wire:key="d-{{ $entry->id }}" x-data="{ open: false }">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span>{{ $entry->received_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i:s') }} · <span class="{{ $entry->status === 'error' ? 'text-red-700 font-semibold' : 'text-gray-700' }}">{{ $entry->statusLabel() }}</span>@if($entry->message) <span class="text-gray-500">– {{ $entry->message }}</span>@endif</span>
                            <span class="flex items-center gap-3">
                                @if($entry->report)<a href="{{ route('einsatzbericht.show', $entry->report) }}" class="text-fw-navy underline">Einsatz {{ $entry->report->number }}</a>@endif
                                <button type="button" x-on:click="open = ! open" class="text-xs text-gray-500 underline">Rohdaten</button>
                            </span>
                        </div>
                        <pre x-show="open" x-cloak class="mt-1 max-h-64 overflow-auto whitespace-pre-wrap break-all rounded bg-gray-900 p-2 text-xs text-gray-100">{{ $entry->method }} · {{ $entry->content_type ?: 'ohne Content-Type' }}

{{ $entry->body }}</pre>
                    </li>
                @empty
                    <li class="py-2 text-gray-500">Noch kein Request eingegangen.</li>
                @endforelse
            </ul>
        </div>
    </section>
</div>

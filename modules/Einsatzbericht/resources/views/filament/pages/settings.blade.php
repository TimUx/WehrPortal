{{-- Filament has no Tailwind utilities for module views: Filament components plus a few local classes --}}
<x-filament-panels::page>
    <style>
        .eb-stack { display: flex; flex-direction: column; gap: 1rem; }
        .eb-check { display: flex; align-items: center; gap: .75rem; font-size: .875rem; padding: .2rem 0; }
        .eb-label { display: block; font-size: .875rem; font-weight: 500; margin-bottom: .25rem; }
        .eb-hint { font-size: .8rem; color: rgb(107 114 128); }
        .eb-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 16rem), 1fr)); gap: .75rem; }
        .eb-row { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
        .eb-code { flex: 1; min-width: 0; word-break: break-all; border-radius: .5rem; background: rgb(243 244 246); padding: .5rem .75rem; font-size: .75rem; font-family: ui-monospace, monospace; }
        .eb-ok { border-radius: .5rem; background: rgb(240 253 244); color: rgb(22 101 52); padding: .5rem .75rem; font-size: .875rem; }
        .eb-error { color: rgb(185 28 28); font-size: .875rem; }
        .eb-test { border: 1px dashed rgb(209 213 219); border-radius: .75rem; padding: 1rem; }
        .eb-fields { display: grid; grid-template-columns: auto 1fr; gap: .1rem .75rem; margin-top: .5rem; font-size: .75rem; }
        .eb-mono { font-family: ui-monospace, monospace; }
        .eb-list > li { padding: .5rem 0; border-top: 1px solid rgb(243 244 246); font-size: .875rem; }
        .eb-raw { margin-top: .25rem; max-height: 16rem; overflow: auto; white-space: pre-wrap; word-break: break-all; border-radius: .25rem; background: rgb(17 24 39); color: rgb(243 244 246); padding: .5rem; font-size: .75rem; }
        .eb-link { text-decoration: underline; font-size: .8rem; }
        .dark .eb-code { background: rgb(255 255 255 / .06); }
        .dark .eb-ok { background: rgb(20 83 45 / .4); color: rgb(187 247 208); }
        .dark .eb-list > li { border-color: rgb(255 255 255 / .08); }
    </style>

    <div class="eb-stack">
        @if($flash)<p class="eb-ok">{{ $flash }}</p>@endif

        {{-- Mandatory fields --}}
        <x-filament::section heading="Pflichtfelder" description="Was ausgefüllt sein muss, bevor der Einsatzleiter einen Bericht abschließen kann. Im Bericht sind diese Felder mit * markiert.">
            <form wire:submit="save" class="eb-stack">
                <div>
                    @foreach($fields as $value => $label)
                        <label class="eb-check"><x-filament::input.checkbox wire:model="required" value="{{ $value }}" /> {{ $label }}</label>
                    @endforeach
                </div>
                <div><x-filament::button type="submit">Pflichtfelder speichern</x-filament::button></div>
            </form>
        </x-filament::section>

        {{-- What counts in Jahresstatistik and Jahresbericht --}}
        <x-filament::section heading="Statistik und Jahresbericht">
            <form wire:submit="saveCounting" class="eb-stack">
                <label class="eb-check"><x-filament::input.checkbox wire:model="releasedOnly" /> Nur freigegebene Berichte zählen</label>
                <p class="eb-hint">Empfohlen: Erst nach der Prüfung durch die Wehrführung fließt ein Einsatz in Jahresstatistik, Verbrauch und Jahresbericht ein – so bleiben die Zahlen sauber. Ausgeschaltet zählen auch abgeschlossene, noch nicht freigegebene Berichte.</p>
                <div><x-filament::button type="submit">Speichern</x-filament::button></div>
            </form>
        </x-filament::section>

        {{-- DIVERA 24/7 --}}
        <x-filament::section heading="DIVERA 24/7 (Webhook)" description="Bei jeder Alarmierung schickt DIVERA die Einsatzdaten an die Adresse unten. Daraus entsteht ein aktueller Einsatz mit Stichwort, Meldung, Adresse und Alarmzeit; Besatzung, Ausrück- und Endzeiten trägt der Einsatzleiter nach. Nur mit DIVERA-Lizenz ALARM oder PRO.">
            <div class="eb-stack">
                <form wire:submit="saveDivera" class="eb-stack">
                    <label class="eb-check" style="font-weight: 500"><x-filament::input.checkbox wire:model="diveraEnabled" /> Webhook eingeschaltet</label>

                    @if($url)
                        <div x-data="{ copied: false }">
                            <span class="eb-label">Adresse für DIVERA</span>
                            <div class="eb-row">
                                <code class="eb-code">{{ $url }}</code>
                                <x-filament::button type="button" color="gray" x-on:click="navigator.clipboard.writeText({{ Js::from($url) }}); copied = true; setTimeout(() => copied = false, 2000)"><span x-text="copied ? 'Kopiert' : 'Kopieren'">Kopieren</span></x-filament::button>
                                <x-filament::button type="button" color="danger" outlined wire:click="regenerateKey" wire:confirm="Neue Adresse erzeugen? Die bisherige funktioniert danach nicht mehr und muss in DIVERA ersetzt werden.">Neu erzeugen</x-filament::button>
                            </div>
                            <p class="eb-hint" style="margin-top: .25rem">Die Adresse ist das Passwort – nicht weitergeben. In DIVERA: Verwaltung der Einheit → Einstellungen → Schnittstellen → Datenübergabe → Webhooks; Methode POST, Format JSON, Inhalt „Textinformationen“.</p>
                        </div>
                    @else
                        <p class="eb-hint">Beim Einschalten wird eine geheime Adresse erzeugt.</p>
                    @endif

                    @if($locationsEnabled)
                        <label>
                            <span class="eb-label">Standort des DIVERA-Webhooks</span>
                            <x-filament::input.wrapper>
                                <x-filament::input.select wire:model="diveraLocationId">
                                    <option value="">Standort auswählen</option>
                                    @foreach($locations as $id => $name)
                                        <option value="{{ $id }}">{{ $name }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                            <p class="eb-hint">Jeder Alarm und Test-Alarm wird diesem Standort zugeordnet. Ist der Standort nicht mehr aktiv, wird der Request als Fehler protokolliert.</p>
                            @error('diveraLocationId')<p class="eb-error">{{ $message }}</p>@enderror
                        </label>
                    @endif

                    <div>
                        <div class="eb-row" style="justify-content: space-between">
                            <span class="eb-label">Feldzuordnung</span>
                            <x-filament::link tag="button" type="button" wire:click="resetMapping" color="gray" size="sm">Vorgabe</x-filament::link>
                        </div>
                        <p class="eb-hint">Welche Felder des Requests in den Bericht kommen. Mehrere Namen mit Komma (der erste vorhandene zählt), verschachtelte mit Punkt, z. B. <code>data.title</code>. Die Namen stehen unten beim letzten Request.</p>
                        <div class="eb-grid" style="margin-top: .5rem">
                            @foreach(\Modules\Einsatzbericht\src\Services\DiveraWebhook::MAPPING_LABELS as $field => $label)
                                <label><span class="eb-label">{{ $label }}</span>
                                    <x-filament::input.wrapper><x-filament::input type="text" wire:model="mapping.{{ $field }}" maxlength="255" class="eb-mono" /></x-filament::input.wrapper>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <label><span class="eb-label">Kein Einsatz bei diesen Stichwörtern (eins pro Zeile, Teil von Stichwort oder Meldung genügt)</span>
                        <x-filament::input.wrapper class="fi-fo-textarea-wrp"><textarea wire:model="filters" rows="3" class="fi-fo-textarea"></textarea></x-filament::input.wrapper>
                    </label>

                    <div><x-filament::button type="submit">DIVERA speichern</x-filament::button></div>
                </form>

                {{-- Test alarm without DIVERA --}}
                <form wire:submit="sendTestAlarm" class="eb-test eb-stack">
                    <div>
                        <p style="font-weight: 600; font-size: .875rem">Test-Alarm</p>
                        <p class="eb-hint">Schickt einen Alarm wie von DIVERA – mit den Feldnamen der Zuordnung oben – durch denselben Ablauf: Rohdaten, Filter, Duplikate, Einsatz, Geocoding. Ohne „Probealarm“ entsteht ein echter Einsatz, der danach gelöscht werden kann. Ob DIVERA dieselben Feldnamen schickt, zeigt erst ein echter Alarm.</p>
                    </div>
                    <div class="eb-grid">
                        <label><span class="eb-label">Stichwort</span><x-filament::input.wrapper><x-filament::input type="text" wire:model="test.keyword" maxlength="150" /></x-filament::input.wrapper></label>
                        <label><span class="eb-label">Adresse</span><x-filament::input.wrapper><x-filament::input type="text" wire:model="test.address" maxlength="255" placeholder="z. B. Dorfstraße 1, Musterstadt" /></x-filament::input.wrapper></label>
                    </div>
                    <label><span class="eb-label">Meldung</span><x-filament::input.wrapper class="fi-fo-textarea-wrp"><textarea wire:model="test.message" rows="2" class="fi-fo-textarea"></textarea></x-filament::input.wrapper></label>
                    <div class="eb-grid">
                        <label><span class="eb-label">Breitengrad (optional)</span><x-filament::input.wrapper><x-filament::input type="text" inputmode="decimal" wire:model="test.latitude" placeholder="51.1657" /></x-filament::input.wrapper></label>
                        <label><span class="eb-label">Längengrad (optional)</span><x-filament::input.wrapper><x-filament::input type="text" inputmode="decimal" wire:model="test.longitude" placeholder="10.4515" /></x-filament::input.wrapper></label>
                    </div>
                    @foreach(['test.keyword', 'test.latitude', 'test.longitude'] as $field)@error($field)<p class="eb-error">{{ $message }}</p>@enderror @endforeach
                    <label class="eb-check"><x-filament::input.checkbox wire:model="test.probe" /> Als Probealarm kennzeichnen (wird gefiltert, kein Einsatz)</label>
                    <label class="eb-check"><x-filament::input.checkbox wire:model="test.via_url" /> Über die öffentliche Adresse senden (prüft auch Erreichbarkeit und Schlüssel)</label>
                    <div class="eb-row">
                        <x-filament::button type="submit" color="gray" wire:loading.attr="disabled" wire:target="sendTestAlarm">Test-Alarm senden</x-filament::button>
                        @if($testResult)<p class="{{ str_contains($testResult, 'fehlgeschlagen') || str_contains($testResult, 'Fehler') ? 'eb-error' : 'eb-ok' }}">{{ $testResult }}</p>@endif
                    </div>
                </form>

                <div>
                    <span class="eb-label">Letzte Requests</span>
                    <p class="eb-hint">Jeder Request wird unverändert gespeichert und nach {{ \Modules\Einsatzbericht\src\Models\DiveraRequest::KEEP_DAYS }} Tagen gelöscht.</p>
                    @if($latestFields)
                        <details open style="margin-top: .5rem; font-size: .75rem">
                            <summary style="cursor: pointer; font-weight: 600">Felder im letzten Request</summary>
                            <dl class="eb-fields">
                                @foreach(array_slice($latestFields, 0, 60, true) as $name => $value)
                                    <dt class="eb-mono">{{ $name }}</dt><dd style="word-break: break-all" class="eb-hint">{{ is_scalar($value) ? \Illuminate\Support\Str::limit((string) $value, 120) : '…' }}</dd>
                                @endforeach
                            </dl>
                        </details>
                    @endif
                    <ul class="eb-list" style="margin-top: .5rem">
                        @forelse($requests as $entry)
                            <li wire:key="d-{{ $entry->id }}" x-data="{ open: false }">
                                <div class="eb-row" style="justify-content: space-between">
                                    <span>{{ $entry->received_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i:s') }} · <span @class(['eb-error' => $entry->status === 'error']) style="{{ $entry->status === 'error' ? 'font-weight: 600' : '' }}">{{ $entry->statusLabel() }}</span>@if($entry->message) <span class="eb-hint">– {{ $entry->message }}</span>@endif</span>
                                    <span class="eb-row">
                                        @if($entry->report)<x-filament::link :href="route('einsatzbericht.show', $entry->report)" size="sm">Einsatz {{ $entry->report->number }}</x-filament::link>@endif
                                        <button type="button" x-on:click="open = ! open" class="eb-link eb-hint">Rohdaten</button>
                                    </span>
                                </div>
                                <pre x-show="open" x-cloak class="eb-raw">{{ $entry->method }} · {{ $entry->content_type ?: 'ohne Content-Type' }}

{{ $entry->body }}</pre>
                            </li>
                        @empty
                            <li class="eb-hint">Noch kein Request eingegangen.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>

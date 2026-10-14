@php($input = 'rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red')
<div class="mx-auto max-w-3xl space-y-4">
    <div>
        <a href="{{ route('ausruestung.equipment') }}" class="inline-flex items-center gap-1 rounded-lg py-1.5 pr-2 text-sm font-medium text-fw-navy hover:text-fw-red">← Geräte</a>
        <h1 class="text-xl font-bold text-gray-900">Inventur</h1>
        <p class="text-sm text-gray-500">Raum oder Fahrzeug wählen, dann alles scannen, was da ist – mit Barcode- oder RFID-Lesegerät, per NFC am Handy oder durch Abhaken.</p>
    </div>

    @if($saved)
        <div class="space-y-1 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-900">
            <p class="font-semibold">Inventur {{ $saved->location_name }} abgeschlossen: {{ count($saved->found) }} gefunden, {{ count($saved->missing) }} fehlen{{ $saved->moved ? ', '.count($saved->moved).' hierher umgebucht' : '' }}.</p>
            @if($saved->missing)<p>Fehlt: {{ collect($saved->missing)->map(fn ($m) => $m['name'].' ('.$m['code'].')')->implode(', ') }}</p>@endif
        </div>
    @endif
    @error('stocktake')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror

    <select wire:model.live="locationId" class="{{ $input }} w-full" aria-label="Raum oder Fahrzeug">
        <option value="">Raum oder Fahrzeug wählen …</option>
        @foreach($locations as $l)<option value="{{ $l->id }}">{{ $l->name }}{{ $l->isVehicle() ? ' (Fahrzeug)' : '' }}</option>@endforeach
    </select>

    @if($location)
        <form wire:submit="scan" class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm"
              x-data="{ nfc: 'NDEFReader' in window, on: false,
                        async start() { try { const r = new NDEFReader(); await r.scan(); this.on = true; r.onreading = (e) => { $wire.set('code', e.serialNumber); $wire.scan(); }; } catch (e) { this.on = false; } } }">
            <div class="flex gap-2">
                <input type="text" wire:model="code" autofocus autocomplete="off" placeholder="Code scannen oder eintippen" class="{{ $input }} min-w-0 flex-1">
                <button type="submit" class="shrink-0 rounded-lg bg-fw-navy px-4 py-2 text-sm font-semibold text-white">Erfassen</button>
            </div>
            <template x-if="nfc"><button type="button" x-on:click="start()" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm" x-text="on ? 'NFC läuft – Tags ans Handy halten' : 'NFC-Tags lesen'"></button></template>
            @if($last)<p class="text-sm {{ str_starts_with($last, '✓') ? 'text-green-800' : 'text-amber-800' }}">{{ $last }}</p>@endif
            <p class="text-sm text-gray-700"><strong>{{ $foundCount }}</strong> von {{ $expected->count() }} gefunden</p>
        </form>

        <section class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
            @forelse($expected as $item)
                @php($isFound = in_array($item->id, $found, true))
                <label class="flex cursor-pointer items-center justify-between gap-3 p-3 text-sm" wire:key="e-{{ $item->id }}">
                    <span class="flex items-center gap-2"><input type="checkbox" wire:click="toggle({{ $item->id }})" @checked($isFound) class="rounded border-gray-300">
                        <span><span class="{{ $isFound ? 'text-gray-900' : 'text-gray-700' }}">{{ $item->name }}</span>
                            <span class="block text-xs text-gray-500">{{ $item->code }}{{ $item->compartment ? ' · '.$item->compartment->name : '' }}{{ $item->category ? ' · '.$item->category->name : '' }}</span></span></span>
                    <span class="shrink-0 text-xs {{ $isFound ? 'font-semibold text-green-700' : ($item->status->value === 'loaned' ? 'text-blue-700' : 'text-gray-400') }}">{{ $isFound ? 'gefunden' : ($item->status->value === 'loaned' ? 'ausgeliehen' : 'offen') }}</span>
                </label>
            @empty
                <p class="p-3 text-sm text-gray-500">Hier ist nichts gebucht.</p>
            @endforelse
        </section>

        @if($foreign->isNotEmpty())
            <section class="space-y-1 rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm">
                <p class="font-semibold text-amber-900">Gefunden, aber woanders gebucht</p>
                @foreach($foreign as $item)<p>{{ $item->name }} ({{ $item->code }}) – gebucht: {{ $item->placeLabel() }}</p>@endforeach
                <label class="flex items-center gap-2 pt-1"><input type="checkbox" wire:model="moveHere" class="rounded border-gray-300"> beim Abschließen hierher umbuchen</label>
            </section>
        @endif
        @if($unknown)
            <p class="rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-700">Unbekannte Codes: {{ implode(', ', $unknown) }} – ggf. als Gerät anlegen oder den Tag am Gerät zuordnen.</p>
        @endif

        <div class="space-y-2">
            <textarea wire:model="notes" rows="2" maxlength="2000" placeholder="Bemerkung (optional)" class="{{ $input }} w-full"></textarea>
            <button type="button" wire:click="finish" wire:confirm="Inventur abschließen? Fehlende Geräte werden im Verlauf vermerkt." class="rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white">Inventur abschließen</button>
        </div>
    @endif

    @if($history->isNotEmpty())
        <section>
            <p class="mb-1 text-sm font-semibold text-gray-700">Letzte Inventuren</p>
            <ul class="space-y-0.5 text-xs text-gray-600">
                @foreach($history as $h)<li>{{ $h->finished_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }} · {{ $h->location_name }} · {{ count($h->found) }}/{{ $h->expected }} gefunden{{ $h->missing ? ', fehlt: '.collect($h->missing)->pluck('name')->implode(', ') : '' }} · {{ $h->user?->name }}</li>@endforeach
            </ul>
        </section>
    @endif
</div>

@php($tones = ['green' => 'bg-green-100 text-green-800', 'amber' => 'bg-amber-100 text-amber-800', 'red' => 'bg-red-100 text-red-800', 'blue' => 'bg-blue-100 text-blue-800', 'gray' => 'bg-gray-100 text-gray-700'])
@php($money = fn (?int $c) => $c !== null ? \App\Support\Money::format($c) : '–')
<div class="mx-auto max-w-4xl space-y-4">
    <div>
        <h1 class="text-xl font-bold text-gray-900">Bestand</h1>
        <p class="text-sm text-gray-500">Alles an einem Ort: {{ $labels ? implode(', ', $labels) : 'noch kein Modul mit Bestand eingeschaltet' }}.</p>
    </div>

    @if($canPlan)
        <div class="flex gap-2 text-sm print:hidden">
            <button type="button" wire:click="$set('tab', 'suche')" class="rounded-full px-3 py-1 {{ $view === 'suche' ? 'bg-fw-navy text-white' : 'bg-gray-100 text-gray-700' }}">Suchen & Scannen</button>
            <button type="button" wire:click="$set('tab', 'ersatz')" class="rounded-full px-3 py-1 {{ $view === 'ersatz' ? 'bg-fw-navy text-white' : 'bg-gray-100 text-gray-700' }}">Ersatzbeschaffung</button>
        </div>
    @endif

    @if($view === 'suche')
        <form wire:submit="lookup" class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm"
              x-data="{ nfc: 'NDEFReader' in window, reading: false, error: '',
                        async read() {
                            this.error = ''; this.reading = true;
                            try {
                                const reader = new NDEFReader();
                                await reader.scan();
                                reader.onreading = (e) => { this.reading = false; $wire.set('q', e.serialNumber); $wire.lookup(); };
                            } catch (e) { this.reading = false; this.error = 'NFC ist nicht verfügbar oder wurde nicht erlaubt.'; }
                        } }">
            <label for="inv-q" class="block text-sm font-medium text-gray-700">Name, Inventarnummer, Seriennummer, Kennzeichen – oder scannen</label>
            <div class="flex gap-2">
                <input id="inv-q" type="search" wire:model.live.debounce.400ms="q" autofocus autocomplete="off" placeholder="z. B. AR-0012, HLF, Helm, MU-FW 112"
                       class="min-w-0 flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
                <button type="submit" class="shrink-0 rounded-lg bg-fw-navy px-4 py-2 text-sm font-semibold text-white">Öffnen</button>
            </div>
            <p class="text-xs text-gray-500">Barcode- und RFID-Lesegeräte tippen den Code in das Feld und schicken ihn ab; ein bekannter Code öffnet das Objekt direkt.</p>
            <template x-if="nfc">
                <button type="button" x-on:click="read()" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm" x-text="reading ? 'NFC-Tag ans Handy halten …' : 'NFC-Tag lesen'"></button>
            </template>
            <p x-show="error" x-text="error" class="text-sm text-red-600"></p>
            @if($notFound)<p class="text-sm text-red-700">„{{ $notFound }}“ ist nirgends im Bestand. Ein neuer RFID-/NFC-Tag wird in der Ausrüstungsverwaltung am Gerät zugeordnet.</p>@endif
        </form>

        @foreach($results as $module => $group)
            <section class="space-y-1" wire:key="g-{{ $module }}">
                <p class="text-sm font-semibold text-gray-700">{{ $group['label'] }} <span class="font-normal text-gray-500">({{ $group['rows']->count() }})</span></p>
                <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
                    @foreach($group['rows'] as $row)
                        <a href="{{ $row['url'] }}" class="flex flex-wrap items-center justify-between gap-2 p-3 text-sm hover:bg-gray-50">
                            <span class="min-w-0"><span class="font-medium text-gray-900">{{ $row['title'] }}</span>
                                <span class="block text-xs text-gray-500">{{ collect([$row['code'] ?? null, $row['place'] ?? null])->filter()->implode(' · ') }}</span></span>
                            @if(filled($row['state'] ?? null))<span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold {{ $tones[$row['tone'] ?? 'gray'] ?? $tones['gray'] }}">{{ $row['state'] }}</span>@endif
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach
        @if(mb_strlen(trim($q)) >= 2 && $results->isEmpty() && ! $notFound)<p class="text-sm text-gray-500">Nichts gefunden.</p>@endif
    @else
        <div class="flex flex-wrap items-center justify-between gap-2 print:hidden">
            <label class="text-sm text-gray-700">Die nächsten
                <select wire:model.live="years" class="rounded-lg border border-gray-300 px-2 py-1 text-sm">@foreach([3, 5, 10, 15, 20] as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach</select> Jahre
            </label>
            <button type="button" onclick="window.print()" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">Drucken</button>
        </div>
        <p class="text-sm text-gray-500">Was in den nächsten Jahren ersetzt werden muss: Geräte, deren Lebensdauer endet (geschätzt mit dem Kaufpreis), und Fahrzeuge mit geplanter Ersatzbeschaffung. Grundlage für Haushalt und Investitionsplanung.</p>
        @if($overdue->isNotEmpty())
            <section class="space-y-1">
                <p class="text-sm font-semibold text-red-700">Schon fällig ({{ $overdue->count() }}) · {{ $money($overdue->sum('cost_cents')) }}</p>
                @include('livewire.inventory.replacement-rows', ['rows' => $overdue])
            </section>
        @endif
        @forelse($byYear as $y => $rows)
            <section class="space-y-1" wire:key="y-{{ $y }}">
                <p class="text-sm font-semibold text-gray-900">{{ $y }} <span class="font-normal text-gray-500">· {{ $rows->count() }} · {{ $money($rows->sum('cost_cents')) }}</span></p>
                @include('livewire.inventory.replacement-rows', ['rows' => $rows])
            </section>
        @empty
            @if($overdue->isEmpty())<p class="text-sm text-gray-500">In diesem Zeitraum steht nichts an.</p>@endif
        @endforelse
        @if($replacements->isNotEmpty())<p class="text-sm font-semibold text-gray-900">Gesamt: {{ $money($replacements->sum('cost_cents')) }}</p>@endif
    @endif
</div>

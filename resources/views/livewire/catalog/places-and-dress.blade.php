@php($input = 'rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
@php($card = 'space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm')
<div class="space-y-4">
    <div>
        <h1 class="text-xl font-bold text-gray-900">Orte &amp; Kleidung</h1>
        <p class="text-sm text-gray-500">Diese Listen teilen sich alle Module (z. B. Kalender und Übungsdienstplaner): Was du hier änderst, steht überall gleich zur Auswahl.</p>
    </div>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif

    <section class="{{ $card }}">
        <h2 class="text-sm font-semibold text-gray-900">Orte</h2>
        <p class="text-xs text-gray-500">Mit Adresse bekommt der Ort bei jedem Termin einen Link zur Navigation (Google Maps).</p>
        <ul class="divide-y divide-gray-100 text-sm">
            @forelse($places as $i => $p)
                <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="p-{{ $p->id }}">
                    <span class="min-w-0"><span class="font-medium text-gray-900">{{ $p->name }}</span>@if($p->address)<span class="block text-xs text-gray-500">{{ $p->address }} · <a href="{{ $p->navigationUrl() }}" target="_blank" rel="noopener" class="text-fw-navy underline">Navigation testen</a></span>@endif</span>
                    <span class="flex items-center gap-3 text-xs">
                        @if($i > 0)<button type="button" wire:click="move('place', {{ $p->id }}, -1)" class="text-gray-500" aria-label="nach oben">▲</button>@endif
                        @if(! $loop->last)<button type="button" wire:click="move('place', {{ $p->id }}, 1)" class="text-gray-500" aria-label="nach unten">▼</button>@endif
                        <button type="button" wire:click="editPlace({{ $p->id }})" class="text-fw-navy underline">Ändern</button>
                        <button type="button" wire:click="deletePlace({{ $p->id }})" wire:confirm="„{{ $p->name }}“ löschen? Termine behalten den Namen als Ort." class="text-red-600 underline">Löschen</button>
                    </span>
                </li>
            @empty
                <li class="py-2 text-gray-500">Noch keine Orte.</li>
            @endforelse
        </ul>
        <form wire:submit="savePlace" class="grid grid-cols-1 gap-2 border-t border-gray-100 pt-3 sm:grid-cols-5">
            <input type="text" wire:model="place.name" maxlength="150" placeholder="z. B. Gerätehaus Musterstadt" class="{{ $input }} sm:col-span-2">
            <input type="text" wire:model="place.address" maxlength="255" placeholder="Straße, PLZ Ort" class="{{ $input }} sm:col-span-2">
            <button type="submit" class="rounded-lg bg-fw-navy px-3 py-2 text-sm font-semibold text-white">{{ $editingPlace ? 'Speichern' : 'Hinzufügen' }}</button>
        </form>
        @error('place')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
    </section>

    <section class="{{ $card }}">
        <h2 class="text-sm font-semibold text-gray-900">Kleidung</h2>
        <p class="text-xs text-gray-500">Was zu einem Termin anzuziehen ist, z. B. Einsatzkleidung, Dienstuniform oder Sportkleidung; bei einem Termin lassen sich mehrere wählen.</p>
        <ul class="divide-y divide-gray-100 text-sm">
            @forelse($dressCodes as $i => $d)
                <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="d-{{ $d->id }}">
                    <span class="font-medium text-gray-900">{{ $d->name }}</span>
                    <span class="flex items-center gap-3 text-xs">
                        @if($i > 0)<button type="button" wire:click="move('dress', {{ $d->id }}, -1)" class="text-gray-500" aria-label="nach oben">▲</button>@endif
                        @if(! $loop->last)<button type="button" wire:click="move('dress', {{ $d->id }}, 1)" class="text-gray-500" aria-label="nach unten">▼</button>@endif
                        <button type="button" wire:click="editDress({{ $d->id }})" class="text-fw-navy underline">Ändern</button>
                        <button type="button" wire:click="deleteDress({{ $d->id }})" wire:confirm="„{{ $d->name }}“ löschen? Bei Terminen fällt sie weg." class="text-red-600 underline">Löschen</button>
                    </span>
                </li>
            @empty
                <li class="py-2 text-gray-500">Noch keine Kleidung.</li>
            @endforelse
        </ul>
        <form wire:submit="saveDress" class="flex flex-wrap gap-2 border-t border-gray-100 pt-3">
            <input type="text" wire:model="dress" maxlength="100" placeholder="z. B. Einsatzkleidung" class="{{ $input }} min-w-0 flex-1">
            <button type="submit" class="rounded-lg bg-fw-navy px-3 py-2 text-sm font-semibold text-white">{{ $editingDress ? 'Speichern' : 'Hinzufügen' }}</button>
        </form>
        @error('dress')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
    </section>
</div>

@php($input = 'rounded-lg border border-gray-300 px-3 py-2')
<div class="mx-auto max-w-5xl space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-bold">Hydrantenkontrolle{{ $year ? ' '.$year->year : '' }}</h1>
        @if($year)<x-page-action :href="route('hydrantenkontrolle.pdf', $year)">PDF-Bericht</x-page-action>@endif
    </div>
    @if(session('flash'))<p role="status" class="rounded-lg bg-green-50 p-3 text-green-800">{{ session('flash') }}</p>@endif
    @include('hydrantenkontrolle::partials.errors')
    <details class="rounded-xl border bg-white p-4" @if(!$year) open @endif>
        <summary class="cursor-pointer font-semibold">Jahr anlegen oder fehlende aktive Hydranten ergänzen</summary>
        <form wire:submit="openYear" class="mt-3 flex flex-wrap items-end gap-3">
            <label>Jahr<input wire:model="newYear" type="number" min="2000" max="2100" required class="{{ $input }} ml-2 w-28"></label>
            <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-fw-navy px-4 py-2 text-white">Öffnen / ergänzen</button>
            <p class="w-full text-sm text-gray-600">Ergänzt nur fehlende aktive Hydranten am aktuellen Standort. Bestehende Prüfungen und Stammdaten bleiben unverändert.</p>
        </form>
    </details>
    <div class="flex flex-wrap gap-3" aria-label="Vorhandene Jahreskontrollen">
        @forelse($years as $available)
            <a href="{{ route('hydrantenkontrolle.year', $available) }}" @if($year?->id === $available->id) aria-current="page" @endif class="rounded-lg border bg-white px-3 py-2 text-fw-navy">
                {{ $available->year }}@if($available->ext_location_id) · {{ $available->location?->name ?? 'Standort '.$available->ext_location_id }}@endif
            </a>
        @empty
            <p class="text-gray-600">Noch keine Jahreskontrolle angelegt. Zuerst Hydranten erfassen, dann das gewünschte Jahr öffnen.</p>
        @endforelse
    </div>
    @if($year)
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach($counts as $label => $count)
                <div class="rounded-xl border bg-white p-3"><p class="text-sm text-gray-600">{{ $label }}</p><p class="text-2xl font-bold">{{ $count }}</p></div>
            @endforeach
        </div>
        @if($editing)
            <form wire:submit="save" wire:key="inspection-editor-{{ $editing->id }}" class="space-y-4 rounded-2xl border bg-white p-4">
                <h2 class="font-bold">{{ $editing->snapshot['city'] ?? '' }} · Nr. {{ $editing->snapshot['number'] }} · {{ $editing->snapshot['street'] ?? '' }} {{ $editing->snapshot['house_number'] ?? '' }}</h2>
                <p class="text-sm text-gray-600">{{ $editing->snapshot['location_description'] ?? '' }}</p>
                @if(isset($editing->snapshot['latitude']))<p class="text-sm text-gray-600">{{ $editing->snapshot['latitude'] }}, {{ $editing->snapshot['longitude'] }}</p>@endif
                <label class="block">Prüfdatum <input wire:model="form.checked_at" type="date" min="{{ $year->year }}-01-01" max="{{ $year->year }}-12-31" class="{{ $input }}"></label>
                <p class="text-sm text-gray-600">Als Prüfer wird beim Speichern {{ auth()->user()->name }} eingetragen. Ohne Prüfdatum und ohne Häkchen bleibt der Hydrant ungeprüft; Bemerkungen sind bereits möglich.</p>
                <fieldset class="space-y-2">
                    <legend class="mb-2 font-semibold">Prüfergebnis</legend>
                    <label class="flex items-start gap-2"><input wire:model="form.is_ok" type="checkbox" class="mt-1"> Unterflurhydrant in Ordnung</label>
                    @foreach($defects as $key => $label)
                        <label class="flex items-start gap-2"><input wire:model="form.defects" type="checkbox" value="{{ $key }}" class="mt-1"> {{ $label }}</label>
                    @endforeach
                </fieldset>
                <label class="block">Bemerkungen<textarea wire:model="form.notes" rows="4" class="{{ $input }} mt-1 w-full"></textarea></label>
                <div class="flex gap-3">
                    <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-fw-red px-4 py-2 font-semibold text-white">Kontrolle speichern</button>
                    <button type="button" wire:click="closeEditor" class="text-fw-navy">Abbrechen</button>
                </div>
            </form>
        @endif
        <div class="flex flex-wrap gap-3">
            <input wire:model.live.debounce.300ms="search" type="search" class="{{ $input }}" aria-label="Ort, Hydrant oder Straße suchen" placeholder="Ort, Nummer oder Straße">
            <select wire:model.live="status" class="{{ $input }}" aria-label="Prüfstatus">
                <option value="">Alle Prüfstände</option><option value="unchecked">Ungeprüft</option><option value="ok">In Ordnung</option><option value="defects">Mit Mängeln</option>
            </select>
        </div>
        <div class="divide-y rounded-2xl border bg-white">
            @forelse($inspections as $inspection)
                <div wire:key="inspection-{{ $inspection->id }}" class="flex flex-wrap items-center justify-between gap-3 p-4">
                    <div>
                        <p class="font-semibold">{{ $inspection->snapshot['city'] ?? '' }} · Nr. {{ $inspection->snapshot['number'] }} · {{ $inspection->snapshot['street'] ?? '' }} {{ $inspection->snapshot['house_number'] ?? '' }}</p>
                        <p class="text-sm text-gray-600">{{ $inspection->snapshot['location_description'] ?? '' }}</p>
                        <p class="text-sm">{{ $inspection->statusLabel() }} · {{ $inspection->checked_at?->format('d.m.Y') ?? 'Kein Prüfdatum' }} · {{ $inspection->inspector_name ?? 'Noch kein Prüfer' }}</p>
                    </div>
                    <div class="flex gap-2">
                        <a href="{{ route('hydrantenkontrolle.history', $inspection->hydrant_id) }}" class="rounded-lg border px-3 py-2 text-fw-navy">Historie</a>
                        <button type="button" wire:click="edit({{ $inspection->id }})" class="rounded-lg border px-3 py-2 text-fw-navy">Bearbeiten</button>
                    </div>
                </div>
            @empty
                <p class="p-4 text-gray-600">Keine Kontrollen gefunden. Falls Hydranten fehlen: das Jahr oben erneut öffnen / ergänzen.</p>
            @endforelse
        </div>
        {{ $inspections->links() }}
    @endif
</div>

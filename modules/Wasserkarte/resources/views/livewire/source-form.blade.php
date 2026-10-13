@php($input = 'mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
@php($error = 'text-sm text-red-600')
<form wire:submit="save" class="mx-auto max-w-3xl space-y-4">
    <h1 class="text-xl font-bold text-gray-900">{{ $source ? $source->label().' bearbeiten' : 'Neue Wasserquelle' }}</h1>
    @error('wasserkarte')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror

    <section class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <label class="block text-sm text-gray-700">Art
                <select wire:model.live="form.kind" class="{{ $input }}">@foreach(\Modules\Wasserkarte\src\Models\Source::KINDS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
            </label>
            <label class="block text-sm text-gray-700">Nummer (optional)<input type="text" wire:model="form.number" maxlength="30" placeholder="z. B. H-012" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Lage<input type="text" wire:model="form.location" maxlength="200" placeholder="z. B. vor Hauptstraße 12" class="{{ $input }}"></label>
        </div>
        @error('kind')<p class="{{ $error }}">{{ $message }}</p>@enderror
    </section>

    <section class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">Position</p>
        <div wire:ignore x-data="waterPicker({ lat: @js($form['latitude'] !== '' ? (float) $form['latitude'] : null), lng: @js($form['longitude'] !== '' ? (float) $form['longitude'] : null) })" class="space-y-2">
            <div x-ref="map" class="h-72 w-full overflow-hidden rounded-xl border border-gray-200"></div>
            <button type="button" x-on:click="gps" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700">Mein Standort (GPS) übernehmen</button>
        </div>
        <p class="text-xs text-gray-500">Auf die Karte tippen oder den Punkt ziehen. Direkt am Hydranten ist „Mein Standort“ am genauesten.</p>
        <div class="grid grid-cols-2 gap-3">
            <label class="block text-xs text-gray-600">Breitengrad<input type="text" inputmode="decimal" wire:model="form.latitude" class="{{ $input }}"></label>
            <label class="block text-xs text-gray-600">Längengrad<input type="text" inputmode="decimal" wire:model="form.longitude" class="{{ $input }}"></label>
        </div>
        @error('latitude')<p class="{{ $error }}">{{ $message }}</p>@enderror
    </section>

    <section class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">Leistung</p>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <label class="block text-sm text-gray-700">Durchfluss (l/min)<input type="number" wire:model="form.flow_lpm" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Ruhedruck (bar)<input type="text" inputmode="decimal" wire:model="form.pressure_bar" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Nennweite DN<input type="number" wire:model="form.pipe_dn" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Inhalt (m³)<input type="number" wire:model="form.capacity_m3" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Saughöhe (m)<input type="text" inputmode="decimal" wire:model="form.suction_height_m" class="{{ $input }}"></label>
        </div>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="block text-sm text-gray-700">Betreiber / Wasserversorger<input type="text" wire:model="form.operator" maxlength="150" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Prüfintervall (Monate, leer = keine Prüffrist)<input type="number" min="1" max="120" wire:model="form.inspection_interval_months" class="{{ $input }}"></label>
        </div>
        @error('inspection_interval_months')<p class="{{ $error }}">{{ $message }}</p>@enderror
        <label class="block text-sm text-gray-700">Notizen (z. B. Zufahrt, Saughöhe, Schlüssel)<textarea wire:model="form.notes" rows="2" class="{{ $input }}"></textarea></label>
        <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="form.is_active" class="rounded"> Auf der Karte (aus = zurückgebaut)</label>
    </section>

    <div class="flex items-center gap-3">
        <button type="submit" class="rounded-lg bg-fw-red px-5 py-2.5 text-sm font-semibold text-white hover:bg-fw-red-dark">Speichern</button>
        <a href="{{ $source ? route('wasserkarte.source', $source) : route('wasserkarte.index') }}" class="text-sm text-gray-500 underline">Abbrechen</a>
    </div>
</form>

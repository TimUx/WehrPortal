@php($input = 'mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
@php($error = 'text-sm text-red-600')
<form wire:submit="save" class="mx-auto max-w-3xl space-y-4">
    <h1 class="text-xl font-bold text-gray-900">{{ $vehicle ? $vehicle->name.' bearbeiten' : 'Neues Fahrzeug' }}</h1>
    @error('fahrzeuge')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror

    <section class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">Grunddaten <span class="font-normal text-gray-500">(auch in Fahrzeugbuchung und Einsatzbericht)</span></p>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="block text-sm text-gray-700">Name<input type="text" wire:model="form.name" maxlength="255" placeholder="z. B. HLF 20" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Kurzname (Handy, optional)<input type="text" wire:model="form.short_name" maxlength="255" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Farbe im Kalender<input type="color" wire:model="form.color" class="mt-1 h-10 w-20 rounded-lg border border-gray-300"></label>
            <label class="block text-sm text-gray-700">Reihenfolge<input type="number" wire:model="form.sort_order" class="{{ $input }}"></label>
        </div>
        @error('name')<p class="{{ $error }}">{{ $message }}</p>@enderror
        @error('color')<p class="{{ $error }}">{{ $message }}</p>@enderror
        <label class="block text-sm text-gray-700">Beschreibung (optional)<textarea wire:model="form.description" rows="2" class="{{ $input }}"></textarea></label>
    </section>

    <section class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">Stammdaten</p>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <label class="block text-sm text-gray-700">Funkrufname<input type="text" wire:model="form.call_sign" maxlength="50" placeholder="z. B. Florian Musterstadt 1/46-1" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Kennzeichen<input type="text" wire:model="form.license_plate" maxlength="20" placeholder="z. B. MU-FW 112" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Fahrzeugart
                <select wire:model="form.category" class="{{ $input }}"><option value="">–</option>@foreach(\Modules\Fahrzeuge\src\Models\Profile::CATEGORIES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
            </label>
            <label class="block text-sm text-gray-700">Typ / Norm<input type="text" wire:model="form.vehicle_type" maxlength="80" placeholder="z. B. HLF 20 (DIN 14530-27)" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Fahrgestell<input type="text" wire:model="form.chassis" maxlength="100" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Aufbau<input type="text" wire:model="form.body_builder" maxlength="100" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Baujahr<input type="number" wire:model="form.year_built" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Erstzulassung<input type="date" wire:model="form.first_registration" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Fahrgestellnummer (FIN)<input type="text" wire:model="form.vin" maxlength="30" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Zul. Gesamtmasse (kg)<input type="number" wire:model="form.gross_weight_kg" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Besatzung<input type="text" wire:model="form.crew" maxlength="20" placeholder="z. B. 1/8" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Führerscheinklasse<input type="text" wire:model="form.license_class" maxlength="30" placeholder="z. B. C, C1, Feuerwehrführerschein" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Löschwasser (l)<input type="number" wire:model="form.water_liters" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Schaummittel (l)<input type="number" wire:model="form.foam_liters" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Pumpe<input type="text" wire:model="form.pump" maxlength="80" placeholder="z. B. FPN 10-2000" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Kilometerstand<input type="number" wire:model="form.mileage_km" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Betriebsstunden<input type="text" inputmode="decimal" wire:model="form.operating_hours" class="{{ $input }}"></label>
            @if($locations->isNotEmpty())
                <label class="block text-sm text-gray-700">Beladung (Ausrüstung)
                    <select wire:model="form.location_id" class="{{ $input }}"><option value="">–</option>@foreach($locations as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach</select>
                </label>
            @endif
        </div>
        @foreach(['category', 'year_built', 'first_registration', 'gross_weight_kg', 'water_liters', 'foam_liters', 'mileage_km', 'location_id'] as $field)@error($field)<p class="{{ $error }}">{{ $message }}</p>@enderror @endforeach
    </section>

    <section class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">Beschaffung</p>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="block text-sm text-gray-700">Gekauft / in Dienst gestellt am<input type="date" wire:model="form.purchased_on" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Kaufpreis in €<input type="text" inputmode="decimal" wire:model="form.purchase_price" placeholder="0,00" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Lieferant / Aufbauhersteller<input type="text" wire:model="form.supplier" maxlength="150" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Förderung<input type="text" wire:model="form.funding" maxlength="150" placeholder="z. B. Landesförderung 40 %" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Garantie bis<input type="date" wire:model="form.warranty_until" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Ersatzbeschaffung geplant (Jahr)<input type="number" min="1990" wire:model="form.replacement_year" placeholder="z. B. 2034" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Geschätzte Kosten der Ersatzbeschaffung in €<input type="text" inputmode="decimal" wire:model="form.replacement_cost" class="{{ $input }}"></label>
        </div>
        <p class="text-xs text-gray-500">Die Ersatzbeschaffung erscheint in der Investitionsplanung unter Bestand → Ersatzbeschaffung.</p>
        @foreach(['purchased_on', 'purchase_price', 'warranty_until', 'replacement_year', 'replacement_cost'] as $field)@error($field)<p class="{{ $error }}">{{ $message }}</p>@enderror @endforeach
    </section>

    <section class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">Ausstattung</p>
        <div class="flex flex-wrap gap-2">
            @foreach($features as $i => $feature)
                <span class="flex items-center gap-1 rounded-full bg-gray-100 px-3 py-1 text-sm" wire:key="f-{{ $i }}">{{ $feature }}<button type="button" wire:click="removeFeature({{ $i }})" class="text-gray-500" aria-label="entfernen">×</button></span>
            @endforeach
        </div>
        <div class="flex gap-2">
            <input type="text" wire:model="newFeature" wire:keydown.enter.prevent="addFeature" placeholder="z. B. Hydraulischer Rettungssatz, Wärmebildkamera, Lichtmast" class="{{ $input }} mt-0">
            <button type="button" wire:click="addFeature" class="shrink-0 rounded-lg border border-gray-300 px-3 py-2 text-sm">Hinzufügen</button>
        </div>
        <label class="block text-sm text-gray-700">Notizen<textarea wire:model="form.notes" rows="3" class="{{ $input }}"></textarea></label>
    </section>

    <section class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">Foto</p>
        <input type="file" wire:model="photo" accept="image/*" class="text-sm">
        <span wire:loading wire:target="photo" class="text-sm text-gray-500">Wird hochgeladen …</span>
        @error('photo')<p class="{{ $error }}">{{ $message }}</p>@enderror
    </section>

    <div class="flex items-center gap-3">
        <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-fw-red px-5 py-2.5 text-sm font-semibold text-white hover:bg-fw-red-dark disabled:opacity-50">Speichern</button>
        <a href="{{ $vehicle ? route('fahrzeuge.show', $vehicle) : route('fahrzeuge.index') }}" class="text-sm text-gray-500 underline">Abbrechen</a>
    </div>
</form>

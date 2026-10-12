@php($input = 'mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
@php($error = 'text-sm text-red-600')
@php($card = 'space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm')
<form wire:submit="save" class="mx-auto max-w-3xl space-y-4">
    <h1 class="text-xl font-bold text-gray-900">{{ $object ? $object->name.' bearbeiten' : 'Neues Objekt' }}</h1>
    @error('objektplaene')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror

    <section class="{{ $card }}">
        <label class="block text-sm text-gray-700">Name<input type="text" wire:model="form.name" maxlength="150" placeholder="z. B. Grundschule Musterstadt" class="{{ $input }}"></label>
        @error('name')<p class="{{ $error }}">{{ $message }}</p>@enderror
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="block text-sm text-gray-700">Objekttyp
                <select wire:model="form.object_type" class="{{ $input }}">@foreach(\Modules\Objektplaene\src\Models\PlanObject::TYPES as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
            </label>
            <label class="block text-sm text-gray-700">Gefährdungsklasse
                <select wire:model="form.hazard_class" class="{{ $input }}">@foreach(\Modules\Objektplaene\src\Models\PlanObject::HAZARD_CLASSES as $v => $l)<option value="{{ $v }}">{{ $l }} – {{ \Modules\Objektplaene\src\Models\PlanObject::HAZARD_HINTS[$v] }}</option>@endforeach</select>
            </label>
        </div>
        @error('object_type')<p class="{{ $error }}">{{ $message }}</p>@enderror
        @error('hazard_class')<p class="{{ $error }}">{{ $message }}</p>@enderror
        <div class="flex flex-wrap gap-4 text-sm text-gray-700">
            <label class="flex items-center gap-2"><input type="checkbox" wire:model="form.has_bma" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> Brandmeldeanlage</label>
            <label class="flex items-center gap-2"><input type="checkbox" wire:model="form.has_fsd" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> Feuerwehrschlüsseldepot</label>
            <label class="flex items-center gap-2"><input type="checkbox" wire:model="form.has_hazardous_materials" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> Gefahrstoffe</label>
        </div>
    </section>

    <section class="{{ $card }}">
        <p class="text-sm font-semibold text-gray-900">Lage</p>
        <div class="flex flex-wrap items-end gap-2">
            <label class="block min-w-0 flex-1 text-sm text-gray-700">Adresse<input type="text" wire:model="form.address" maxlength="200" placeholder="z. B. Schulstraße 1, Musterstadt" class="{{ $input }}"></label>
            @if($canGeocode)<button type="button" wire:click="locateAddress" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700">Position aus Adresse</button>@endif
        </div>
        <div wire:ignore x-data="waterPicker({ lat: @js($form['latitude'] !== '' ? (float) $form['latitude'] : null), lng: @js($form['longitude'] !== '' ? (float) $form['longitude'] : null) })"
             x-on:object-position.window="place([$event.detail.lat, $event.detail.lng]); map.setView([$event.detail.lat, $event.detail.lng], 17)" class="space-y-2">
            <div x-ref="map" class="h-72 w-full overflow-hidden rounded-xl border border-gray-200"></div>
            <button type="button" x-on:click="gps" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700">Mein Standort (GPS) übernehmen</button>
        </div>
        <p class="text-xs text-gray-500">Auf die Karte tippen oder den Punkt ziehen – am besten auf den Haupteingang bzw. die Feuerwehrzufahrt.</p>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <label class="block text-xs text-gray-600">Breitengrad<input type="text" inputmode="decimal" wire:model="form.latitude" class="{{ $input }}"></label>
            <label class="block text-xs text-gray-600">Längengrad<input type="text" inputmode="decimal" wire:model="form.longitude" class="{{ $input }}"></label>
            <label class="col-span-2 block text-xs text-gray-600 sm:col-span-1">Umkreis des Objekts (m)<input type="number" min="20" max="2000" wire:model="form.radius_m" class="{{ $input }}"></label>
        </div>
        <p class="text-xs text-gray-500">Liegt eine Einsatzstelle in diesem Umkreis (plus 100 m Toleranz), werden die Pläne im Einsatz vorgeschlagen. Für große Gelände (Werk, Hof) größer wählen.</p>
        @error('latitude')<p class="{{ $error }}">{{ $message }}</p>@enderror
        @error('radius_m')<p class="{{ $error }}">{{ $message }}</p>@enderror
    </section>

    <section class="{{ $card }}">
        @foreach(['hazards' => ['Besondere Gefahren', 'z. B. Gasflaschenlager im Keller, Photovoltaik auf dem Dach'], 'access' => ['Zufahrt, Anleiterstellen, BMA / FIBS', 'z. B. Zufahrt über Hofeinfahrt Nord, FIBS im Haupteingang'], 'contacts' => ['Ansprechpartner im Objekt', 'z. B. Hausmeister, Telefon'], 'notes' => ['Hinweise', '']] as $field => [$label, $hint])
            <label class="block text-sm text-gray-700">{{ $label }}<textarea wire:model="form.{{ $field }}" rows="3" placeholder="{{ $hint }}" class="{{ $input }}"></textarea></label>
        @endforeach
    </section>

    <section class="{{ $card }}">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="block text-sm text-gray-700">Unterlagen überprüfen alle … Monate<input type="number" min="1" max="120" wire:model="form.review_interval_months" placeholder="leer = ohne Frist" class="{{ $input }}"></label>
            <label class="flex items-center gap-2 self-end text-sm text-gray-700"><input type="checkbox" wire:model="form.is_active" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> Aktiv (sonst archiviert)</label>
        </div>
        @error('review_interval_months')<p class="{{ $error }}">{{ $message }}</p>@enderror
    </section>

    <div class="flex gap-2">
        <button type="submit" class="rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white">Speichern</button>
        <a href="{{ $object ? route('objektplaene.object', $object) : route('objektplaene.index') }}" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700">Abbrechen</a>
    </div>
</form>

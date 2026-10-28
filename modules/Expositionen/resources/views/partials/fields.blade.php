{{-- The fields of an exposure, bound to $form in the Livewire component --}}
@php($in = 'mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
@php($E = \Modules\Expositionen\src\Models\Exposure::class)
<div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
    @if(($showLocations ?? false) && isset($locations) && $locations->isNotEmpty() && ! isset($reportId) && ! isset($exposure))
        <label class="block text-sm text-gray-700">Standort
            <select wire:model.live="form.ext_location_id" class="{{ $in }}">
                <option value="">– wählen –</option>
                @foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach
            </select>
        </label>
        @error('ext_location_id')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
    @elseif(isset($reportLocation) && $reportLocation)
        <p class="text-sm text-gray-700">Standort: <span class="font-medium">{{ $reportLocation->name }}</span></p>
    @elseif(($showLocations ?? false) && isset($exposure) && $exposure?->organizationalLocation)
        <p class="text-sm text-gray-700">Standort: <span class="font-medium">{{ $exposure->organizationalLocation->name }}</span></p>
    @endif
    <label class="block text-sm text-gray-700">Datum<input type="date" wire:model="form.occurred_on" max="{{ now('Europe/Berlin')->toDateString() }}" class="{{ $in }}"></label>
    <label class="block text-sm text-gray-700 sm:col-span-2">Anlass<input type="text" wire:model="form.occasion" maxlength="200" placeholder="z. B. Einsatz 2027/012 · FEU 2 oder Übung Brandübungsanlage" class="{{ $in }}"></label>
</div>
@foreach(['occurred_on', 'occasion'] as $f)@error($f)<p class="text-sm text-red-600">{{ $message }}</p>@enderror @endforeach
<div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
    <label class="block text-sm text-gray-700">Tätigkeit
        <select wire:model="form.activity" class="{{ $in }}"><option value="">– wählen –</option>@foreach($E::ACTIVITIES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
    <label class="block text-sm text-gray-700 sm:col-span-2">Genauer (optional)<input type="text" wire:model="form.activity_text" maxlength="300" placeholder="z. B. Kellerbrand, Kunststoffe und Kabel" class="{{ $in }}"></label>
</div>
@error('activity')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
<fieldset>
    <legend class="text-sm text-gray-700">Gefahrstoffe</legend>
    <div class="mt-1 grid grid-cols-1 gap-1 sm:grid-cols-2">
        @foreach($E::SUBSTANCES as $s)<label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="form.substances" value="{{ $s }}" class="rounded"> {{ $s }}</label>@endforeach
    </div>
    <input type="text" wire:model="form.other_substances" maxlength="500" placeholder="weitere, mit Komma getrennt – gern mit CAS-Nummer" class="{{ $in }}">
    @error('substances')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
</fieldset>
<div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
    <label class="block text-sm text-gray-700">Dauer (Minuten, ca.)<input type="number" min="1" max="1440" wire:model="form.duration_minutes" class="{{ $in }}"></label>
    <fieldset class="sm:col-span-2"><legend class="text-sm text-gray-700">Aufgenommen</legend>
        <div class="mt-1 flex flex-wrap gap-3">@foreach($E::ROUTES as $k => $l)<label class="flex items-center gap-1.5 text-sm text-gray-700"><input type="checkbox" wire:model="form.routes" value="{{ $k }}" class="rounded"> {{ $l }}</label>@endforeach</div></fieldset>
</div>
@error('duration_minutes')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
<fieldset><legend class="text-sm text-gray-700">Schutzmaßnahmen</legend>
    <div class="mt-1 grid grid-cols-1 gap-1 sm:grid-cols-2">@foreach($E::PROTECTION as $k => $l)<label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="form.protection" value="{{ $k }}" class="rounded"> {{ $l }}</label>@endforeach</div>
</fieldset>
<label class="block text-sm text-gray-700">Bemerkung (optional, keine Befunde)<textarea wire:model="form.notes" rows="2" maxlength="1000" class="{{ $in }}"></textarea></label>

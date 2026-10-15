@php($input = 'mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
<form wire:submit="save" class="mx-auto max-w-2xl space-y-4 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
    <h1 class="text-xl font-bold text-gray-900">{{ $item ? $item->name.' bearbeiten' : 'Neuer Schlüssel' }}</h1>
    @error('issue')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <label class="block text-sm text-gray-700 sm:col-span-2">Wofür<input type="text" wire:model="form.name" maxlength="150" placeholder="z. B. Gerätehaus Haupteingang" class="{{ $input }}"></label>
        <label class="block text-sm text-gray-700">Nummer<input type="text" wire:model="form.code" maxlength="30" placeholder="{{ $nextCode ?? '' }}" class="{{ $input }}"></label>
    </div>
    @error('name')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
    @error('code')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <label class="block text-sm text-gray-700">Art<select wire:model="form.kind" class="{{ $input }}">@foreach(\Modules\Schluessel\src\Models\Key::KINDS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select></label>
        <label class="block text-sm text-gray-700">Schließanlage / Schließgruppe<input type="text" wire:model="form.locking" maxlength="150" placeholder="z. B. Gruppe A" class="{{ $input }}"></label>
    </div>
    <p class="text-sm font-medium text-gray-700">Schließt (optional)</p>
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <label class="block text-xs text-gray-600">Ort<select wire:model="form.place_id" class="{{ $input }}"><option value="">–</option>@foreach($places as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
        <label class="block text-xs text-gray-600">Fahrzeug<select wire:model="form.vehicle_id" class="{{ $input }}"><option value="">–</option>@foreach($vehicles as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
        @if($objects->isNotEmpty())
            <label class="block text-xs text-gray-600">Objekt (Objektpläne)<select wire:model="form.object_id" class="{{ $input }}"><option value="">–</option>@foreach($objects as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
        @endif
    </div>
    <label class="block text-sm text-gray-700">Aufbewahrung, wenn nicht ausgegeben<input type="text" wire:model="form.storage" maxlength="150" placeholder="z. B. Schlüsselkasten Wachraum" class="{{ $input }}"></label>
    <label class="block text-sm text-gray-700">Notizen<textarea wire:model="form.notes" rows="3" class="{{ $input }}"></textarea></label>
    <div class="flex gap-2">
        <button type="submit" class="rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white">Speichern</button>
        <a href="{{ $item ? route('schluessel.show', $item) : route('schluessel.index') }}" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700">Abbrechen</a>
    </div>
</form>

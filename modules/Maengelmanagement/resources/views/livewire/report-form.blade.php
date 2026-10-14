<div class="max-w-2xl mx-auto">
    <a href="{{ route('maengelmanagement.index') }}" class="inline-flex items-center gap-1 rounded-lg py-1.5 pr-2 text-sm font-medium text-fw-navy hover:text-fw-red">← Alle Mängel</a>
    <h1 class="text-xl font-bold text-gray-900 mt-1 mb-4">Mangel melden</h1>

    <form wire:submit="save" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 space-y-5">
        {{-- What is affected --}}
        <div>
            <label for="mm-device" class="block text-sm font-medium text-gray-700 mb-1">Gerät / Objekt</label>
            <select id="mm-device" wire:model.live="deviceId" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
                <option value="">– nicht in der Liste –</option>
                @foreach($devices as $device)
                    <option value="{{ $device->id }}">{{ $device->label() }} · {{ $device->code }}</option>
                @endforeach
            </select>
            @error('deviceId')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror

            @if(blank($deviceId) && $vehicles->isNotEmpty())
                <label for="mm-vehicle" class="mt-3 block text-sm font-medium text-gray-700 mb-1">Fahrzeug</label>
                <select id="mm-vehicle" wire:model.live="vehicleId" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
                    <option value="">– kein Fahrzeug –</option>
                    @foreach($vehicles as $vehicle)<option value="{{ $vehicle->id }}">{{ $vehicle->name }}</option>@endforeach
                </select>
            @endif

            @if(blank($deviceId) && blank($vehicleId) && $equipment->isNotEmpty())
                <label for="mm-equipment" class="mt-3 block text-sm font-medium text-gray-700 mb-1">Gerät der Ausrüstung</label>
                <select id="mm-equipment" wire:model.live="equipmentId" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
                    <option value="">– kein Gerät –</option>
                    @foreach($equipment as $item)<option value="{{ $item->id }}">{{ $item->name }} · {{ $item->code }}</option>@endforeach
                </select>
                @if($equipmentId)<p class="mt-1 text-xs text-gray-500">Das Gerät wird in der Ausrüstungsverwaltung als defekt gekennzeichnet, bis der Mangel erledigt ist.</p>@endif
            @endif

            @if(blank($deviceId) && blank($vehicleId) && blank($equipmentId))
                <input type="text" wire:model="location" maxlength="150" placeholder="Wo ist der Mangel? z. B. Fahrzeughalle, Tor 2"
                       class="mt-2 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
                @error('location')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            @endif
        </div>

        <div>
            <label for="mm-title" class="block text-sm font-medium text-gray-700 mb-1">Was ist kaputt? <span class="text-red-600">*</span></label>
            <input id="mm-title" type="text" wire:model="title" maxlength="150" placeholder="z. B. Schlauchtrommel klemmt"
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
            @error('title')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="mm-desc" class="block text-sm font-medium text-gray-700 mb-1">Beschreibung</label>
            <textarea id="mm-desc" wire:model="description" rows="4" maxlength="5000"
                      class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red"></textarea>
            @error('description')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        {{-- Urgency --}}
        <fieldset>
            <legend class="block text-sm font-medium text-gray-700 mb-2">Dringlichkeit</legend>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                @foreach($urgencies as $level)
                    <label class="flex items-start gap-3 rounded-lg border p-3 cursor-pointer {{ $urgency === $level->value ? 'border-fw-red bg-red-50' : 'border-gray-200 hover:border-gray-300' }}">
                        <input type="radio" wire:model.live="urgency" value="{{ $level->value }}" class="mt-0.5 text-fw-red focus:ring-fw-red">
                        <span>
                            <span class="block text-sm font-semibold text-gray-900">{{ $level->label() }}</span>
                            <span class="block text-xs text-gray-500">{{ $level->hint() }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            @error('urgency')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </fieldset>

        {{-- Photos --}}
        <div>
            <label for="mm-photos" class="block text-sm font-medium text-gray-700 mb-1">Fotos (bis zu 3)</label>
            <input id="mm-photos" type="file" wire:model="photos" accept="image/*" multiple
                   class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-fw-navy file:px-3 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:opacity-90">
            <div wire:loading wire:target="photos" class="mt-1 text-sm text-gray-500">Lädt hoch …</div>
            @error('photos')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            @error('photos.*')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror

            @if(count($photos) > 0)
                <div class="mt-3 flex flex-wrap gap-3">
                    @foreach($photos as $i => $photo)
                        @if(method_exists($photo, 'isPreviewable') && $photo->isPreviewable())
                            <div class="relative" wire:key="photo-{{ $i }}">
                                <img src="{{ $photo->temporaryUrl() }}" alt="" class="h-20 w-20 rounded-lg object-cover border border-gray-200">
                                <button type="button" wire:click="removePhoto({{ $i }})" class="absolute -top-2 -right-2 h-6 w-6 rounded-full bg-gray-900 text-white text-xs" aria-label="Foto entfernen">×</button>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" wire:loading.attr="disabled" wire:target="save,photos"
                    class="inline-flex items-center rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white hover:bg-fw-red-dark disabled:opacity-50">
                Mangel melden
            </button>
            <a href="{{ route('maengelmanagement.index') }}" class="text-sm text-gray-500 hover:text-gray-900">Abbrechen</a>
        </div>
    </form>
</div>

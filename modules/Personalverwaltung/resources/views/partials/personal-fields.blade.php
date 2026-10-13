{{-- The switched-on personal data, bound to "data.*" --}}
@if(in_array('address', $fields, true))
    <div class="space-y-2">
        <label class="block text-sm text-gray-700">Straße und Hausnummer<input type="text" wire:model="data.street" maxlength="150" autocomplete="street-address" class="{{ $input }}"></label>
        <div class="grid grid-cols-3 gap-2">
            <label class="block text-sm text-gray-700">PLZ<input type="text" inputmode="numeric" wire:model="data.postal_code" maxlength="5" autocomplete="postal-code" class="{{ $input }}"></label>
            <label class="col-span-2 block text-sm text-gray-700">Ort<input type="text" wire:model="data.city" maxlength="100" autocomplete="address-level2" class="{{ $input }}"></label>
        </div>
        @error('postal_code')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
@endif
@if(in_array('birth_date', $fields, true))
    <label class="block text-sm text-gray-700">Geburtsdatum<input type="date" wire:model="data.birth_date" autocomplete="bday" class="{{ $input }}"></label>
    @error('birth_date')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
@endif
@if(in_array('phone', $fields, true))
    <label class="block text-sm text-gray-700">Mobilnummer<input type="tel" wire:model="data.phone" maxlength="30" autocomplete="tel" class="{{ $input }}"></label>
    @error('phone')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
@endif

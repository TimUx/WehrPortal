@php($input = 'mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
@php($error = 'text-sm text-red-600')
<form wire:submit="save" class="mx-auto max-w-2xl space-y-4">
    <h1 class="text-xl font-bold text-gray-900">Tanken</h1>
    <section class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="block text-sm text-gray-700">Fahrzeug
                <select wire:model.live="vehicleId" class="{{ $input }}"><option value="">– bitte wählen –</option>@foreach($vehicles as $v)<option value="{{ $v->id }}">{{ $v->name }}{{ $v->standort ? ' ('.$v->standort->name.')' : '' }}</option>@endforeach</select>
            </label>
            @if($selectedVehicle?->standort)<p class="text-xs text-gray-500">Standort: {{ $selectedVehicle->standort->name }}</p>@endif
            <label class="block text-sm text-gray-700">Zeitpunkt<input type="datetime-local" wire:model="fueledAt" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Kilometerstand<input type="number" inputmode="numeric" wire:model="km" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Kraftstoff
                <select wire:model="fuel" class="{{ $input }}">@foreach(\Modules\Fahrtenbuch\src\Models\Fueling::FUELS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
            </label>
            <label class="block text-sm text-gray-700">Liter<input type="text" inputmode="decimal" wire:model="liters" placeholder="z. B. 84,32" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Betrag in € (optional)<input type="text" inputmode="decimal" wire:model="cost" placeholder="0,00" class="{{ $input }}"></label>
        </div>
        @foreach(['vehicle_id', 'fueled_at', 'km', 'fuel', 'liters', 'cost'] as $field)@error($field)<p class="{{ $error }}">{{ $message }}</p>@enderror @endforeach
        <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="fullTank" class="rounded text-fw-red focus:ring-fw-red"> Voll getankt (für die Verbrauchsberechnung)</label>
        <label class="block text-sm text-gray-700">Tankstelle (optional)<input type="text" wire:model="station" maxlength="150" class="{{ $input }}"></label>
        <label class="block text-sm text-gray-700">Beleg (Foto oder PDF, optional)<input type="file" wire:model="receipt" accept="image/*,application/pdf" class="mt-1 text-sm"></label>
        @error('receipt')<p class="{{ $error }}">{{ $message }}</p>@enderror
        <label class="block text-sm text-gray-700">Bemerkung (optional)<input type="text" wire:model="notes" maxlength="500" class="{{ $input }}"></label>
    </section>
    <div class="flex items-center gap-3">
        <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-fw-red px-5 py-2.5 text-sm font-semibold text-white hover:bg-fw-red-dark disabled:opacity-50">Eintragen</button>
        <a href="{{ route('fahrtenbuch.index') }}" class="text-sm text-gray-500 underline">Abbrechen</a>
    </div>
</form>

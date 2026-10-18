@php($input = 'mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2')
<form wire:submit="save" class="mx-auto max-w-3xl space-y-4">
    <h1 class="text-xl font-bold">{{ $hydrantId ? 'Hydrant bearbeiten' : 'Hydrant erfassen' }}</h1>
    @include('hydrantenkontrolle::partials.errors')
    <p class="text-sm text-gray-600">Bitte Straße, Lagebeschreibung oder Koordinaten angeben. Bereits angelegte Jahreskontrollen behalten ihre ursprünglichen Stammdaten.</p>
    <div class="grid gap-4 rounded-2xl border bg-white p-4 sm:grid-cols-2">
        <label>Nummer <span aria-hidden="true">*</span><input wire:model="form.number" required maxlength="100" class="{{ $input }}"></label>
        <label>Straße<input wire:model="form.street" maxlength="255" class="{{ $input }}"></label>
        <label>Hausnummer<input wire:model="form.house_number" maxlength="50" class="{{ $input }}"></label>
        <label>Lagebeschreibung<textarea wire:model="form.location_description" rows="3" class="{{ $input }}"></textarea></label>
        <label>Breitengrad<input wire:model="form.latitude" type="number" step="any" min="-90" max="90" class="{{ $input }}"></label>
        <label>Längengrad<input wire:model="form.longitude" type="number" step="any" min="-180" max="180" class="{{ $input }}"></label>
        <label class="flex items-center gap-2"><input wire:model="form.active" type="checkbox"> Aktiv (abwählen zum Archivieren)</label>
    </div>
    <div class="flex items-center gap-4">
        <button wire:loading.attr="disabled" type="submit" class="rounded-lg bg-fw-red px-4 py-2 font-semibold text-white">Speichern</button>
        <a href="{{ route('hydrantenkontrolle.hydrants') }}" class="inline-flex text-fw-navy">Zurück</a>
    </div>
</form>

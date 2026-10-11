@php($input = 'mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
<div class="mx-auto max-w-3xl space-y-4">
    <div>
        <h1 class="text-xl font-bold text-gray-900">Material-Katalog</h1>
        <p class="text-sm text-gray-500">Verbrauchsmaterial, das im Einsatzbericht ausgewählt wird. Die Einheit ist je Material fest; dasselbe Material mit anderer Einheit ist ein eigener Eintrag. Erfasst wird nur der Verbrauch, kein Bestand.</p>
    </div>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif

    <form wire:submit="save" class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm space-y-3">
        <h2 class="text-sm font-semibold text-gray-900">{{ $editingId ? 'Material bearbeiten' : 'Material anlegen' }}</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <label class="block text-sm text-gray-700 sm:col-span-1">Bezeichnung<input type="text" wire:model="name" maxlength="150" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Kategorie<select wire:model="category" class="{{ $input }}">@foreach(\Modules\Einsatzbericht\src\Models\Material::CATEGORIES as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select></label>
            <label class="block text-sm text-gray-700">Einheit<select wire:model="unit" class="{{ $input }}">@foreach(\Modules\Einsatzbericht\src\Models\Material::UNITS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select></label>
        </div>
        @error('name')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="isActive" class="h-5 w-5 rounded border-gray-300 text-fw-red focus:ring-fw-red"> Aktiv (in Berichten auswählbar)</label>
        <div class="flex gap-3">
            <button type="submit" class="rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white">Speichern</button>
            @if($editingId)<button type="button" wire:click="resetForm" class="text-sm text-gray-500 underline">Abbrechen</button>@endif
        </div>
    </form>

    @foreach($materials as $category => $items)
        <section>
            <h2 class="mb-2 text-sm font-semibold text-gray-700">{{ \Modules\Einsatzbericht\src\Models\Material::CATEGORIES[$category] ?? $category }}</h2>
            <div class="rounded-2xl border border-gray-100 bg-white shadow-sm divide-y divide-gray-100 text-sm">
                @foreach($items as $material)
                    <div class="flex items-center justify-between gap-2 px-4 py-2.5 {{ $material->is_active ? '' : 'text-gray-400' }}" wire:key="mat-{{ $material->id }}">
                        <span>{{ $material->label() }}{{ $material->is_active ? '' : ' · inaktiv' }}</span>
                        <span class="flex gap-3">
                            <button type="button" wire:click="edit({{ $material->id }})" class="text-fw-navy underline">Bearbeiten</button>
                            <button type="button" wire:click="delete({{ $material->id }})" wire:confirm="„{{ $material->label() }}“ löschen?" class="text-red-600 underline">Löschen</button>
                        </span>
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach
</div>

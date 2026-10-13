{{-- Ort (saved place or free text) and Kleidung, shared by the forms of Kalender and Übungsdienstplaner.
     Needs Livewire properties placeId, location, dressCodeIds; $manageUrl links to the lists. --}}
<div class="space-y-3">
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <label class="block text-sm font-medium text-gray-700">Ort
            <select wire:model.live="placeId" class="mt-1 {{ $input }}">
                <option value="">– frei eingeben –</option>
                @foreach($places as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
        </label>
        <label class="block text-sm font-medium text-gray-700">{{ filled($placeId ?? '') ? 'Bezeichnung' : 'Ort (frei)' }}
            <input type="text" wire:model="location" maxlength="150" placeholder="z. B. Gerätehaus" @disabled(filled($placeId ?? '')) class="mt-1 {{ $input }} disabled:bg-gray-50">
        </label>
    </div>
    @error('placeId')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
    @if($dressCodes->isNotEmpty())
        <div>
            <p class="mb-1 text-sm font-medium text-gray-700">Kleidung</p>
            <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-gray-700">
                @foreach($dressCodes as $id => $name)
                    <label class="flex items-center gap-2"><input type="checkbox" value="{{ $id }}" wire:model="dressCodeIds" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> {{ $name }}</label>
                @endforeach
            </div>
        </div>
    @endif
    @if(! empty($manageUrl))
        <p class="text-xs text-gray-500">Orte (mit Adresse für die Navigation) und Kleidung pflegst du unter <a href="{{ $manageUrl }}" class="text-fw-navy underline">Orte &amp; Kleidung</a>; die Listen gelten für alle Module.</p>
    @endif
</div>

<div class="rounded-2xl border border-gray-200 bg-white p-5 space-y-3">
    <div class="flex items-center justify-between">
        <h2 class="text-sm font-semibold text-gray-900">Einstellungen</h2>
        @if($saved)
        <span class="text-xs text-fw-green" wire:key="saved-{{ $calendarView }}">Gespeichert</span>
        @endif
    </div>

    <div>
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Standardansicht in den Kalendern (Fahrzeugbuchung und Termine)</p>
        <div class="mt-2 grid grid-cols-3 gap-2">
            @foreach($views as $value => $label)
            <label
                class="flex items-center justify-center py-2 rounded-xl border text-sm font-medium cursor-pointer transition-colors
                       {{ $calendarView === $value ? 'bg-fw-red border-fw-red text-white' : 'border-gray-200 text-gray-700 hover:bg-gray-50' }}"
            >
                <input type="radio" wire:model.live="calendarView" value="{{ $value }}" class="sr-only">
                {{ $label }}
            </label>
            @endforeach
        </div>
        @error('calendarView') <p class="mt-1 text-sm text-fw-red">{{ $message }}</p> @enderror
    </div>
</div>

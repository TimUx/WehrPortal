{{-- Days of a confirmed Lehrgang: date, time and place --}}
@if($sessionsRecordId === $record->id)
    <form wire:submit="saveSessions" class="rounded-xl border border-gray-200 bg-gray-50 p-3 space-y-2">
        <p class="text-sm font-semibold text-gray-900">Termine laut Bestätigung von fox112</p>
        @foreach($sessions as $i => $session)
            <div class="flex flex-wrap items-center gap-2" wire:key="s-{{ $record->id }}-{{ $i }}">
                <span class="w-12 text-xs text-gray-500">{{ $i + 1 }}. Tag</span>
                <input type="date" wire:model="sessions.{{ $i }}.date" class="{{ $input }}" aria-label="Datum">
                <input type="time" wire:model="sessions.{{ $i }}.starts_at" class="{{ $input }}" aria-label="von">
                <span class="text-sm text-gray-500">–</span>
                <input type="time" wire:model="sessions.{{ $i }}.ends_at" class="{{ $input }}" aria-label="bis">
                <input type="text" wire:model="sessions.{{ $i }}.location" maxlength="255" placeholder="Lehrgangsort" class="{{ $input }} min-w-0 flex-1">
                @if(count($sessions) > 1)<button type="button" wire:click="removeSession({{ $i }})" class="text-sm text-red-600" title="Tag entfernen">×</button>@endif
            </div>
            @error("sessions.$i.date")<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            @error("sessions.$i.starts_at")<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        @endforeach
        @error('sessions')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        @error('record')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        <button type="button" wire:click="addSession" class="text-sm text-fw-navy underline">+ weiterer Tag</button>
        <div class="flex gap-3">
            <button type="submit" class="inline-flex items-center rounded-lg bg-fw-red px-3 py-1.5 text-sm font-semibold text-white hover:bg-fw-red-dark">Speichern und Kamerad informieren</button>
            <button type="button" wire:click="closeSessions" class="text-sm text-gray-500 underline">Abbrechen</button>
        </div>
    </form>
@endif

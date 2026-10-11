{{-- Declining a registration with a comment that goes to the Kamerad by mail --}}
@if($rejectRecordId === $record->id)
    <form wire:submit="reject" class="rounded-xl border border-gray-200 bg-gray-50 p-3 space-y-2">
        <input type="text" wire:model="rejectComment" maxlength="1000" placeholder="Kommentar für {{ $record->user->name }} (geht per E-Mail)" class="{{ $input }} w-full">
        @error('rejectComment')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        <div class="flex gap-3">
            <button type="submit" class="inline-flex items-center rounded-lg bg-gray-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-gray-800">Ablehnen und Kamerad informieren</button>
            <button type="button" wire:click="closeSessions" class="text-sm text-gray-500 underline">Abbrechen</button>
        </div>
    </form>
@endif

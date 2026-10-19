{{-- Absagen of the Kamerad and refusals of the Ausbildungsleitung with date and reason, so both see the same --}}
<div class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100 text-sm">
    @foreach($records as $record)
        <div class="p-3" wire:key="x-{{ $record->id }}">
            <p>
                @if($showName)<a href="{{ route('lehrgaenge.member', $record->user) }}" class="font-medium text-gray-900 hover:underline">{{ $record->user->name }}</a> · @endif
                <span class="{{ $showName ? '' : 'font-medium text-gray-900' }}">{{ $record->course->name }}</span>
                <span class="{{ $record->status === 'rejected' ? 'text-red-700' : 'text-gray-500' }}">· {{ $record->status === 'rejected' ? 'von der Ausbildungsleitung abgelehnt' : 'abgesagt' }} am {{ $record->cancelled_at?->setTimezone('Europe/Berlin')->format('d.m.Y') }}{{ $record->confirmed_at ? ' (war bestätigt)' : '' }}</span>
            </p>
            <p class="text-xs text-gray-600">{{ $record->status === 'rejected' ? 'Kommentar' : 'Grund' }}: {{ $record->cancel_reason }}</p>
            @if(($canSee ?? false) && $record->status === 'cancelled')
                <p class="mt-1 text-xs">
                    @if($record->cancel_seen_at)
                        <span class="text-green-700">✓ In fox112 abgemeldet ({{ $record->cancel_seen_at->setTimezone('Europe/Berlin')->format('d.m.Y') }})</span>
                        <button type="button" wire:click="markCancelSeen({{ $record->id }}, false)" class="ml-2 text-gray-500 underline">rückgängig</button>
                    @else
                        <button type="button" wire:click="markCancelSeen({{ $record->id }})" class="rounded-lg border border-gray-300 bg-white px-2 py-1 font-semibold text-gray-700 hover:bg-gray-50">Erledigt – in fox112 abgemeldet</button>
                    @endif
                </p>
            @endif
        </div>
    @endforeach
</div>

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
        </div>
    @endforeach
</div>

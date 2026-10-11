{{-- Absolvierte Lehrgänge of one Kamerad --}}
<div class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100 text-sm">
    @foreach($records as $record)
        <div class="p-3 flex flex-wrap items-center justify-between gap-2" wire:key="done-{{ $record->id }}">
            <span class="font-medium text-gray-900">{{ $record->course->name }}</span>
            <span class="text-gray-500">absolviert {{ $record->completed_on?->format('d.m.Y') }}</span>
        </div>
    @endforeach
</div>

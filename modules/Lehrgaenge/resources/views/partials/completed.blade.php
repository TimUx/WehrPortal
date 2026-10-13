{{-- Absolvierte Lehrgänge of one Kamerad; with $canDelete the Ausbildungsleiter removes ones entered afterwards --}}
<div class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100 text-sm">
    @foreach($records as $record)
        <div class="p-3 flex flex-wrap items-center justify-between gap-2" wire:key="done-{{ $record->id }}">
            <span><span class="font-medium text-gray-900">{{ $record->course->name }}</span>
                @if($record->entered_manually && $record->notes)<span class="block text-xs text-gray-500">{{ $record->notes }}</span>@endif</span>
            <span class="flex items-center gap-3 text-gray-500">absolviert {{ $record->completed_on?->format('d.m.Y') }}{{ $record->entered_manually ? ' · nachgetragen' : '' }}
                @if(($canDelete ?? false) && $record->entered_manually)
                    <button type="button" wire:click="deleteCompleted({{ $record->id }})" wire:confirm="Nachgetragenen Lehrgang löschen?" class="text-xs text-red-600 underline">Löschen</button>
                @endif
            </span>
        </div>
    @endforeach
</div>

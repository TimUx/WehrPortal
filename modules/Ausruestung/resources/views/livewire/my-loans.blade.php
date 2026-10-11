<div>
    @error('loan')<p class="mb-3 text-sm text-red-600">{{ $message }}</p>@enderror

    @if($loans->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 text-sm text-gray-600">
            Du hast zurzeit nichts ausgeliehen oder reserviert. Unter „Alle Geräte“ kannst du ein Gerät reservieren.
        </div>
    @else
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100">
            @foreach($loans as $loan)
                <div class="p-4 flex items-start justify-between gap-3" wire:key="loan-{{ $loan->id }}">
                    <div class="min-w-0">
                        <a href="{{ route('ausruestung.show', $loan->equipment) }}" class="font-semibold text-gray-900 hover:underline">{{ $loan->equipment->name }}</a>
                        <p class="text-sm text-gray-500 mt-0.5">
                            <span class="font-mono">{{ $loan->equipment->code }}</span>
                            · {{ $loan->starts_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }} – {{ $loan->due_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}
                        </p>
                    </div>
                    <div class="flex flex-col items-end gap-1 shrink-0">
                        @if($loan->isOverdue())
                            <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-800">Überfällig</span>
                        @else
                            <span class="px-2 py-0.5 rounded-full text-xs font-semibold {{ $loan->status->value === 'active' ? 'bg-amber-100 text-amber-800' : 'bg-blue-100 text-blue-800' }}">{{ $loan->status->label() }}</span>
                        @endif
                        @if($loan->status->value === 'reserved')
                            <button type="button" wire:click="cancel({{ $loan->id }})" wire:confirm="Reservierung stornieren?" class="text-xs text-gray-500 underline hover:text-gray-900">Stornieren</button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

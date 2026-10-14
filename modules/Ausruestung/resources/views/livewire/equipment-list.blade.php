<div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <h1 class="text-xl font-bold text-gray-900">Geräte</h1>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 mb-4">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Suchen: Name, Code, Seriennummer …"
               class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
        <select wire:model.live="category" class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
            <option value="">Alle Kategorien</option>
            @foreach($categories as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
        </select>
        <select wire:model.live="status" class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
            <option value="">Jeder Status</option>
            @foreach($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
        </select>
    </div>

    @if($equipment->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 text-sm text-gray-600">Keine Geräte gefunden.</div>
    @else
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100">
            @foreach($equipment as $item)
                <a href="{{ route('ausruestung.show', $item) }}" class="block p-4 hover:bg-gray-50" wire:key="e-{{ $item->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-semibold text-gray-900 truncate">{{ $item->name }}</p>
                            <p class="text-sm text-gray-500 mt-0.5 truncate">
                                <span class="font-mono">{{ $item->code }}</span>
                                · {{ $item->placeLabel() }}
                                @if($item->next_inspection_at)· Prüfung {{ $item->next_inspection_at->format('d.m.Y') }}@endif
                            </p>
                        </div>
                        <div class="flex flex-col items-end gap-1 shrink-0">
                            <span class="px-2 py-0.5 rounded-full text-xs font-semibold {{ $item->status->badgeClasses() }}">{{ $item->status->label() }}</span>
                            @foreach($item->warnings() as $warning)
                                <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-800">{{ $warning }}</span>
                            @endforeach
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
        <div class="mt-4">{{ $equipment->links() }}</div>
    @endif
</div>

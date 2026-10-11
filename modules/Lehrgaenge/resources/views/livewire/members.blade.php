<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-xl font-bold text-gray-900">Lehrgänge der Kameraden</h1>
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Name suchen" class="w-full sm:w-64 rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-fw-red focus:ring-fw-red">
    </div>
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100 text-sm">
        @forelse($members as $member)
            <a href="{{ route('lehrgaenge.member', $member) }}" class="flex items-center justify-between gap-2 p-3 hover:bg-gray-50" wire:key="m-{{ $member->id }}">
                <span class="font-medium text-gray-900">{{ $member->name }}</span>
                <span class="text-gray-500">{{ $member->completed }} absolviert</span>
            </a>
        @empty
            <p class="p-3 text-gray-500">Niemand gefunden.</p>
        @endforelse
    </div>
</div>

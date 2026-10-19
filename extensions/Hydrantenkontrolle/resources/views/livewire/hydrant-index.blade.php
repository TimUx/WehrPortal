@php($input = 'rounded-lg border border-gray-300 px-3 py-2')
<div class="mx-auto max-w-5xl space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-bold">Hydranten</h1>
        <x-page-action :href="route('hydrantenkontrolle.create')">Hydrant erfassen</x-page-action>
    </div>
    @if(session('flash'))<p role="status" class="rounded-lg bg-green-50 p-3 text-green-800">{{ session('flash') }}</p>@endif
    <div class="flex flex-wrap gap-3">
        <input wire:model.live.debounce.300ms="search" type="search" class="{{ $input }}" placeholder="Nummer, Straße oder Lage" aria-label="Hydranten suchen">
        <select wire:model.live="status" class="{{ $input }}" aria-label="Hydrantenstatus">
            <option value="active">Aktiv</option><option value="archived">Archiviert</option><option value="all">Alle</option>
        </select>
    </div>
    <div class="divide-y rounded-2xl border bg-white">
        @forelse($hydrants as $hydrant)
        <div wire:key="hydrant-{{ $hydrant->id }}" class="flex flex-wrap items-center justify-between gap-3 p-4 hover:bg-gray-50">
            <a href="{{ route('hydrantenkontrolle.edit', $hydrant) }}" class="min-w-0 flex-1 space-y-1">
                <p class="font-semibold">Nr. {{ $hydrant->number }} · {{ $hydrant->street }} {{ $hydrant->house_number }} @unless($hydrant->active)<span class="text-gray-500">(Archiviert)</span>@endunless</p>
                <p class="text-sm text-gray-600">{{ $hydrant->location_description }}</p>
                @if($hydrant->latitude !== null)<p class="text-sm text-gray-600">{{ $hydrant->latitude }}, {{ $hydrant->longitude }}</p>@endif
            </a>
            <a href="{{ route('hydrantenkontrolle.history', $hydrant) }}" class="rounded-lg border px-3 py-2 text-fw-navy">Historie</a>
        </div>
        @empty
            <p class="p-4 text-gray-600">Keine Hydranten gefunden.</p>
        @endforelse
    </div>
    {{ $hydrants->links() }}
</div>

@php($select = 'rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-fw-red focus:ring-fw-red')
<div class="mx-auto max-w-4xl space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm text-gray-500"><a href="{{ route('fahrzeuge.index') }}" class="underline">Fahrzeuge</a></p>
            <h1 class="text-xl font-bold text-gray-900">Fristen</h1>
            <p class="text-sm text-gray-500">Prüf- und Wartungsfristen aller Fahrzeuge. Erledigt werden sie im Fahrzeugprofil.</p>
        </div>
        <button type="button" onclick="window.print()" class="print:hidden rounded-lg border border-gray-300 px-3 py-1.5 text-sm">Drucken</button>
    </div>

    <div class="flex flex-wrap items-center gap-3 print:hidden">
        <select wire:model.live="filter" class="{{ $select }}" aria-label="Filter">
            <option value="overdue">Überfällig</option>
            <option value="upcoming">Überfällig und nächste 90 Tage</option>
            <option value="all">Alle</option>
        </select>
        <select wire:model.live="vehicleId" class="{{ $select }}" aria-label="Fahrzeug">
            <option value="">Alle Fahrzeuge</option>
            @foreach($vehicles as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach
        </select>
    </div>

    <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
        @forelse($deadlines as $d)
            <a href="{{ route('fahrzeuge.show', $d->vehicle_id) }}" class="grid grid-cols-1 gap-1 p-3 text-sm hover:bg-gray-50 sm:grid-cols-[10rem_1fr_auto] sm:items-center" wire:key="d-{{ $d->id }}">
                <span class="font-semibold text-gray-900">{{ $d->vehicle->name }}</span>
                <span>{{ $d->title }}<span class="block text-xs text-gray-500">{{ $d->interval_months ? 'alle '.$d->interval_months.' Monate' : 'einmalig' }}{{ $d->steps ? ' · '.count($d->steps).' Prüfschritte' : '' }}{{ $d->notes ? ' · '.$d->notes : '' }}</span></span>
                <span class="text-xs font-semibold {{ $d->isOverdue() ? 'text-red-700' : ($d->isDue() ? 'text-amber-800' : 'text-gray-600') }}">{{ $d->isOverdue() ? 'überfällig seit' : 'fällig' }} {{ $d->due_on->format('d.m.Y') }}</span>
            </a>
        @empty
            <p class="p-3 text-sm text-gray-500">{{ $filter === 'overdue' ? 'Nichts überfällig.' : 'Keine Fristen.' }}</p>
        @endforelse
    </div>
</div>

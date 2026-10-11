@php($select = 'rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-fw-red focus:ring-fw-red')
<div class="space-y-4" wire:poll.60s="refresh">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Wasserkarte{{ $einsatz ? ' · '.($einsatz->keyword ?: 'Einsatz '.$einsatz->number) : '' }}</h1>
            <p class="text-sm text-gray-500">
                <span class="text-green-700">{{ $counts['available'] ?? 0 }} verfügbar</span>
                @if($counts['defective'] ?? 0) · <span class="font-semibold text-red-700">{{ $counts['defective'] }} defekt</span>@endif
                @if($counts['locked'] ?? 0) · <span class="text-gray-600">{{ $counts['locked'] }} gesperrt</span>@endif
            </p>
        </div>
        @if($einsatz)<a href="{{ route('wasserkarte.index') }}" class="text-sm text-gray-500 underline">Alle Quellen</a>@endif
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <select wire:model.live="kind" class="{{ $select }}" aria-label="Art"><option value="">Alle Arten</option>@foreach(\Modules\Wasserkarte\src\Models\Source::KINDS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
        <select wire:model.live="status" class="{{ $select }}" aria-label="Status"><option value="">Jeder Status</option>@foreach(\Modules\Wasserkarte\src\Models\Source::STATUSES as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
    </div>

    <div wire:ignore x-data="waterMap({ markers: @js($markers), center: null, einsatz: @js($einsatz ? ['lat' => (float) $einsatz->latitude, 'lng' => (float) $einsatz->longitude] : null), highlight: @js($highlight) })" class="relative overflow-hidden rounded-2xl border border-gray-200">
        <div x-ref="map" class="h-[60vh] min-h-80 w-full"></div>
        <button type="button" x-on:click="locate" class="absolute right-3 top-3 z-[500] rounded-lg bg-white px-3 py-2 text-sm font-semibold text-fw-navy shadow" x-text="locating ? 'Suche …' : 'Mein Standort'"></button>
    </div>

    @if($nearest->isNotEmpty())
        <section class="rounded-2xl border-2 border-fw-navy bg-white p-4 shadow-sm">
            <p class="text-sm font-semibold text-gray-900">{{ $einsatz ? 'Nächste Wasserquellen zur Einsatzstelle' : 'Nächste Wasserquellen zu deinem Standort' }}</p>
            <div class="divide-y divide-gray-100">@include('wasserkarte::partials.nearest', ['nearest' => $nearest])</div>
        </section>
    @endif

    <section class="space-y-2">
        <p class="text-sm font-semibold text-gray-900">Alle Wasserquellen ({{ $sources->count() }})</p>
        <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
            @forelse($sources as $s)
                <a href="{{ route('wasserkarte.source', $s) }}" class="flex items-center justify-between gap-3 p-3 text-sm hover:bg-gray-50" wire:key="s-{{ $s->id }}">
                    <span class="flex min-w-0 items-center gap-2">
                        <span class="h-3 w-3 shrink-0 rounded-full" style="background-color: {{ \Modules\Wasserkarte\src\Models\Source::COLORS[$s->status] }}"></span>
                        <span class="min-w-0"><span class="block truncate text-gray-900">{{ $s->label() }}{{ $s->location ? ' · '.$s->location : '' }}</span><span class="block text-xs text-gray-500">{{ $s->performance() ?: '–' }}</span></span>
                    </span>
                    <span class="shrink-0 text-xs {{ $s->inspectionOverdue() ? 'font-semibold text-amber-800' : 'text-gray-500' }}">{{ $s->status !== 'available' ? $s->statusLabel() : ($s->inspectionOverdue() ? 'Prüfung überfällig' : ($s->last_inspected_on ? 'geprüft '.$s->last_inspected_on->format('m/Y') : '')) }}</span>
                </a>
            @empty
                <p class="p-4 text-sm text-gray-500">Noch keine Wasserquellen.@if($canManage) <a href="{{ route('wasserkarte.create') }}" class="underline">Anlegen</a> oder unter „Prüfungen & Import“ eine Liste des Wasserversorgers einlesen.@endif</p>
            @endforelse
        </div>
    </section>
</div>

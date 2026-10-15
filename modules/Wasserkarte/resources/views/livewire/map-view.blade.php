@php($select = 'rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-fw-red focus:ring-fw-red')
<div class="space-y-4" wire:poll.60s="refresh">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Wasserkarte{{ $einsatz ? ' · '.($einsatz->keyword ?: 'Einsatz '.$einsatz->number) : '' }}</h1>
            <p class="text-sm text-gray-500">
                <span class="text-green-700">{{ $counts['available'] ?? 0 }} verfügbar</span>
                @if($counts['defective'] ?? 0) · <span class="font-semibold text-red-700">{{ $counts['defective'] }} defekt</span>@endif
                @if($counts['locked'] ?? 0) · <span class="text-gray-600">{{ $counts['locked'] }} gesperrt</span>@endif
                @if($due) · <span class="text-amber-800">{{ $due }} mit fälliger Prüfung (gelber Rand)</span>@endif
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            @if($einsatz)<a href="{{ route('wasserkarte.index') }}" class="text-sm text-gray-500 underline">Alle Quellen</a>@endif
            @if($canManage)<x-page-action :href="route('wasserkarte.create')">Neue Wasserquelle</x-page-action>@endif
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <select wire:model.live="kind" class="{{ $select }}" aria-label="Art"><option value="">Alle Arten</option>@foreach(\Modules\Wasserkarte\src\Models\Source::KINDS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
        <select wire:model.live="status" class="{{ $select }}" aria-label="Status"><option value="">Jeder Status</option>@foreach(\Modules\Wasserkarte\src\Models\Source::STATUSES as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach<option value="due">Prüfung fällig (30 Tage)</option></select>
    </div>

    <div wire:ignore x-data="waterMap({ markers: @js($markers), center: null, einsatz: @js($einsatz ? ['lat' => (float) $einsatz->latitude, 'lng' => (float) $einsatz->longitude] : null), highlight: @js($highlight) })" class="relative overflow-hidden rounded-2xl border border-gray-200">
        <div x-ref="map" class="h-[60vh] min-h-80 w-full"></div>
        <div class="absolute right-3 top-3 z-[500] flex flex-col items-end gap-2">
            <button type="button" x-on:click="locate" class="rounded-lg bg-white px-3 py-2 text-sm font-semibold text-fw-navy shadow" x-text="locating ? 'Suche …' : 'Mein Standort'"></button>
            @if($canManage)
                <button type="button" x-on:click="osmPreview" class="rounded-lg bg-white px-3 py-2 text-sm font-semibold text-fw-navy shadow" x-text="loadingOsm ? 'Frage OpenStreetMap …' : 'Aus OpenStreetMap übernehmen'"></button>
            @endif
        </div>
    </div>

    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @error('osm')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror
    @if($osm)
        <section class="space-y-2 rounded-2xl border-2 border-blue-300 bg-blue-50 p-4 text-sm">
            <p class="font-semibold text-blue-900">OpenStreetMap im Kartenausschnitt: {{ $osm['total'] }} Löschwasserquellen</p>
            <ul class="text-blue-900">
                <li><strong>{{ $osm['new'] }}</strong> neu</li>
                <li><strong>{{ $osm['link'] }}</strong> liegen an einer schon erfassten Quelle (bis {{ \Modules\Wasserkarte\src\Services\OsmImporter::MATCH_METERS }} m) und werden mit ihr verknüpft – nur leere Felder werden ergänzt</li>
                <li><strong>{{ $osm['known'] }}</strong> schon übernommen</li>
            </ul>
            @if($osm['sample'])<p class="text-xs text-blue-900">z. B. {{ implode(', ', $osm['sample']) }}</p>@endif
            <p class="text-xs text-blue-900">Die Daten stammen von freiwilligen Kartierern und können fehlen oder veraltet sein – Status und Leistung bitte vor Ort prüfen. Lizenz: © OpenStreetMap-Mitwirkende, ODbL.</p>
            <div class="flex gap-3">
                <button type="button" wire:click="osmImport" wire:loading.attr="disabled" class="rounded-lg bg-fw-navy px-4 py-2 font-semibold text-white" @disabled($osm['new'] + $osm['link'] + $osm['known'] === 0)>Übernehmen</button>
                <button type="button" wire:click="osmCancel" class="text-gray-600 underline">Abbrechen</button>
            </div>
        </section>
    @endif

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

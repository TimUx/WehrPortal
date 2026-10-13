@php($months = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'])
@php($maxMonth = max(1, $stats['byMonth']->max()))
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-bold text-gray-900">Jahresstatistik {{ $year }}</h1>
        <div class="flex items-center gap-2">
            <button type="button" wire:click="$set('year', {{ $year - 1 }})" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm">‹ {{ $year - 1 }}</button>
            <button type="button" wire:click="$set('year', {{ $year + 1 }})" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm">{{ $year + 1 }} ›</button>
            <button type="button" onclick="window.print()" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold">Drucken / PDF</button>
        </div>
    </div>
    <p class="text-sm text-gray-500">Gezählt werden {{ \Modules\Einsatzbericht\src\Services\ReportService::countedStatuses() === ['released'] ? 'freigegebene Berichte' : 'abgeschlossene und freigegebene Berichte' }} nach dem Datum der Alarmierung.@if($stats['drafts'] > 0) {{ $stats['drafts'] }} {{ $stats['drafts'] === 1 ? 'Bericht ist' : 'Berichte sind' }} noch Entwurf oder aktuell.@endif</p>
    @if($stats['waiting'] > 0)<p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">{{ $stats['waiting'] }} abgeschlossene{{ $stats['waiting'] === 1 ? 'r Bericht wartet' : ' Berichte warten' }} noch auf die Freigabe und {{ $stats['waiting'] === 1 ? 'zählt' : 'zählen' }} erst danach. <a href="{{ route('einsatzbericht.index', ['status' => 'closed']) }}" class="underline">Zur Freigabe</a></p>@endif
    <p class="text-sm"><a href="{{ route('einsatzbericht.annual', ['jahr' => $year]) }}" class="font-semibold text-fw-navy underline">Jahresbericht {{ $year }} (alle Module) →</a></p>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        @foreach([
            ['Einsätze', $stats['total']],
            ['Einsatzdauer gesamt', \Modules\Einsatzbericht\src\Models\Report::formatMinutes($stats['minutes'])],
            ['Personalstunden', \Modules\Einsatzbericht\src\Models\Report::formatMinutes($stats['personMinutes'])],
            ['Mittlere Stärke', $stats['averageStrength']],
            ['Gerettete', $stats['rescued']],
            ['Verletzte', $stats['injured']],
            ['Tote', $stats['dead']],
            ['Atemschutzeinsätze (Personen)', $stats['breathing']],
        ] as [$label, $value])
            <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
                <p class="text-xs text-gray-500">{{ $label }}</p>
                <p class="mt-1 text-2xl font-bold text-gray-900">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <section class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <h2 class="mb-2 text-sm font-semibold text-gray-900">Nach Einsatzart</h2>
            @forelse($stats['byCategory'] as $row)
                <div class="flex items-center justify-between border-b border-gray-50 py-1.5 text-sm"><span>{{ $row['label'] }}</span><span class="font-semibold">{{ $row['count'] }}</span></div>
            @empty
                <p class="text-sm text-gray-500">Keine Einsätze.</p>
            @endforelse
        </section>
        <section class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <h2 class="mb-2 text-sm font-semibold text-gray-900">Nach Fahrzeug</h2>
            @forelse($stats['byVehicle'] as $name => $count)
                <div class="flex items-center justify-between border-b border-gray-50 py-1.5 text-sm"><span>{{ $name }}</span><span class="font-semibold">{{ $count }}</span></div>
            @empty
                <p class="text-sm text-gray-500">Keine Fahrzeuge eingesetzt.</p>
            @endforelse
        </section>
    </div>

    <section class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <h2 class="mb-3 text-sm font-semibold text-gray-900">Nach Monat</h2>
        <div class="flex h-40 items-end gap-1.5">
            @foreach($stats['byMonth'] as $month => $count)
                <div class="flex flex-1 flex-col items-center justify-end gap-1">
                    <span class="text-xs text-gray-700">{{ $count ?: '' }}</span>
                    <div class="w-full rounded-t bg-fw-red" style="height: {{ $count / $maxMonth * 100 }}%; min-height: {{ $count ? '4px' : '0' }}"></div>
                    <span class="text-[10px] text-gray-500">{{ $months[$month - 1] }}</span>
                </div>
            @endforeach
        </div>
    </section>

    <section class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <h2 class="mb-2 text-sm font-semibold text-gray-900">Verbrauchsmaterial</h2>
        @forelse($consumption as $block)
            <h3 class="mt-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $block['label'] }}</h3>
            @foreach($block['items'] as $item)
                <div class="flex items-center justify-between border-b border-gray-50 py-1.5 text-sm">
                    <span>{{ $item['name'] }} <span class="text-xs text-gray-500">({{ $item['reports'] }} {{ $item['reports'] === 1 ? 'Einsatz' : 'Einsätze' }})</span></span>
                    <span class="font-semibold">{{ \Modules\Einsatzbericht\src\Models\ReportMaterial::amount($item['quantity'], $item['unit']) }}</span>
                </div>
            @endforeach
        @empty
            <p class="text-sm text-gray-500">Kein Verbrauch erfasst.</p>
        @endforelse
    </section>

    <section class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm" wire:key="map-{{ $year }}">
        <h2 class="mb-2 text-sm font-semibold text-gray-900">Karte der Einsätze ({{ $points->count() }} mit Koordinaten)</h2>
        <div wire:ignore x-data="einsatzOverviewMap(@js($points))" class="overflow-hidden rounded-xl border border-gray-200"><div x-ref="map" class="h-80 w-full"></div></div>
    </section>
</div>

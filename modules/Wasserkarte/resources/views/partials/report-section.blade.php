{{-- Löschwasser at the Einsatzstelle, in the Einsatzbericht and the Lagemeldungen; needs the point of the Einsatz --}}
@if($report->latitude && $report->longitude)
    @php($water = app(\Modules\Wasserkarte\src\Services\WaterService::class))
    @php($nearest = $water->nearest((float) $report->latitude, (float) $report->longitude))
    <section class="{{ $class ?? 'mx-auto mt-4 max-w-4xl' }} space-y-2 rounded-2xl border-2 border-blue-200 bg-white p-4 shadow-sm">
        <div class="flex items-center justify-between gap-2">
            <p class="text-sm font-semibold text-gray-900">Löschwasser in der Nähe</p>
            <a href="{{ route('wasserkarte.index', ['einsatz' => $report->id]) }}" class="text-xs text-fw-navy underline">Wasserkarte öffnen</a>
        </div>
        @if($nearest->isEmpty())
            <p class="text-sm text-gray-500">Keine Wasserquelle im Umkreis von 1,5 km eingetragen.</p>
        @else
            <div wire:ignore x-data="waterMap({ markers: @js(\Modules\Wasserkarte\src\Services\WaterService::markers($nearest->pluck('source'))), center: null, einsatz: @js(['lat' => (float) $report->latitude, 'lng' => (float) $report->longitude]), highlight: @js($nearest->where('source.status', 'available')->pluck('source.id')->values()) })" class="overflow-hidden rounded-xl border border-gray-200">
                <div x-ref="map" class="h-56 w-full"></div>
            </div>
            <div class="divide-y divide-gray-100">@include('wasserkarte::partials.nearest', ['nearest' => $nearest])</div>
        @endif
    </section>
@endif

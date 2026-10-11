<div class="space-y-4">
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        @foreach([['Einsätze gesamt', $total], ['Einsätze '.now('Europe/Berlin')->year, $year], ['Einsatzstunden', \Modules\Einsatzbericht\src\Models\Report::formatMinutes($minutes)], ['Davon unter Atemschutz', $breathing]] as [$label, $value])
            <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
                <p class="text-xs text-gray-500">{{ $label }}</p>
                <p class="mt-1 text-2xl font-bold text-gray-900">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100">
        @forelse($entries as $entry)
            @php($report = $entry->report)
            <div class="px-4 py-3 text-sm" wire:key="e-{{ $entry->id }}">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <p class="font-semibold text-gray-900">
                        @if($canOpen)<a href="{{ route('einsatzbericht.show', $report) }}" class="hover:underline">{{ $report->keyword ?: 'Einsatz' }}</a>@else{{ $report->keyword ?: 'Einsatz' }}@endif
                        <span class="font-normal text-gray-500">· {{ $report->alarmed_at?->setTimezone('Europe/Berlin')->format('d.m.Y') }}</span>
                    </p>
                    <span class="text-gray-500">{{ \Modules\Einsatzbericht\src\Models\Report::formatMinutes($report->durationMinutes()) }}</span>
                </div>
                <p class="text-gray-600">{{ $entry->reportVehicle->name }} · {{ $entry->functionLabel() }}{{ $entry->breathing_apparatus ? ' · Atemschutz' : '' }}</p>
            </div>
        @empty
            <p class="px-4 py-6 text-sm text-gray-500">{{ $own ? 'Du hast noch keine Einsätze in der App.' : 'Noch keine Einsätze in der App.' }}</p>
        @endforelse
    </div>
    <p class="text-xs text-gray-500">Gezählt werden abgeschlossene und freigegebene Berichte. Die Dauer reicht von der Alarmierung bis zum Einsatzende.</p>
</div>

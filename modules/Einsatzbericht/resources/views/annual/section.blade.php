{{-- Jahresbericht: the Einsätze of the year --}}
<div class="ar-grid">
    @foreach([['Einsätze', $stats['total']], ['Einsatzdauer', \Modules\Einsatzbericht\src\Models\Report::formatMinutes($stats['minutes'])], ['Personalstunden', \Modules\Einsatzbericht\src\Models\Report::formatMinutes($stats['personMinutes'])], ['Mittlere Stärke', $stats['averageStrength']], ['Unter Atemschutz (Personen)', $stats['breathing']], ['Gerettete', $stats['rescued']], ['Verletzte', $stats['injured']], ['Tote', $stats['dead']]] as [$label, $value])
        <div class="ar-tile"><span>{{ $label }}</span><strong>{{ $value }}</strong></div>
    @endforeach
</div>
@if($stats['byCategory']->isNotEmpty())
    <h3>Nach Einsatzart</h3>
    <table><tbody>@foreach($stats['byCategory'] as $row)<tr><td>{{ $row['label'] }}</td><td class="n">{{ $row['count'] }}</td></tr>@endforeach</tbody></table>
@endif
@if($stats['byVehicle']->isNotEmpty())
    <h3>Nach Fahrzeug</h3>
    <table><tbody>@foreach($stats['byVehicle'] as $name => $count)<tr><td>{{ $name }}</td><td class="n">{{ $count }}</td></tr>@endforeach</tbody></table>
@endif
@foreach($stats['details'] ?? [] as $detail)
    <h3>{{ $detail['label'] }}</h3>
    <table><tbody>@foreach($detail['rows'] as $value => $count)<tr><td>{{ $value }}</td><td class="n">{{ $count }}</td></tr>@endforeach</tbody></table>
@endforeach
<h3>Je Monat</h3>
<table><thead><tr>@foreach(['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'] as $m)<th class="n">{{ $m }}</th>@endforeach</tr></thead>
    <tbody><tr>@foreach($stats['byMonth'] as $count)<td class="n">{{ $count }}</td>@endforeach</tr></tbody></table>
<p class="note">Gezählt: {{ \Modules\Einsatzbericht\src\Services\ReportService::countedStatuses() === ['released'] ? 'freigegebene Berichte' : 'abgeschlossene und freigegebene Berichte' }}.@if($stats['waiting']) {{ $stats['waiting'] }} abgeschlossene warten noch auf die Freigabe.@endif @if($stats['sides'] ?? 0) Nebenberichte ({{ $stats['sides'] }}) zählen nicht als eigene Einsätze, ihre Stunden, Fahrzeuge und Atemschutzeinsätze aber mit.@endif</p>

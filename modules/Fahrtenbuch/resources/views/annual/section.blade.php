{{-- Jahresbericht: trips and kilometres per vehicle --}}
<div class="ar-grid"><div class="ar-tile"><span>Fahrten</span><strong>{{ $rows->sum('trips') }}</strong></div><div class="ar-tile"><span>Kilometer</span><strong>{{ number_format($rows->sum('km'), 0, ',', '.') }}</strong></div></div>
<table><thead><tr><th>Fahrzeug</th><th class="n">Fahrten</th><th class="n">km</th></tr></thead>
    <tbody>@foreach($rows as $r)<tr><td>{{ $r['name'] }}</td><td class="n">{{ $r['trips'] }}</td><td class="n">{{ number_format($r['km'], 0, ',', '.') }}</td></tr>@endforeach</tbody></table>

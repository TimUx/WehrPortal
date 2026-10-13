{{-- Jahresbericht: defects reported and closed in the year --}}
<div class="ar-grid"><div class="ar-tile"><span>Gemeldet</span><strong>{{ $reported }}</strong></div><div class="ar-tile"><span>Erledigt</span><strong>{{ $resolved }}</strong></div><div class="ar-tile"><span>Ø Tage bis erledigt</span><strong>{{ $avgDays !== null ? number_format($avgDays, 1, ',', '.') : '–' }}</strong></div><div class="ar-tile"><span>Zum Jahresende offen</span><strong>{{ $openAtEnd }}</strong></div></div>
@if($bySubject->isNotEmpty())
<table><thead><tr><th>Betrifft</th><th class="n">Mängel</th></tr></thead>
    <tbody>@foreach($bySubject as $subject => $n)<tr><td>{{ $subject }}</td><td class="n">{{ $n }}</td></tr>@endforeach</tbody></table>
@endif

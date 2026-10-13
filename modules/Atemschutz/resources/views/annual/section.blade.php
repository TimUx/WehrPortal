{{-- Jahresbericht: Überwachungen, Trupps and Nachweise of the year; the pool as of today in the current year --}}
<div class="ar-grid">
    @foreach($operations as $label => $count)<div class="ar-tile"><span>Überwachungen {{ $label }}</span><strong>{{ $count }}</strong></div>@endforeach
    <div class="ar-tile"><span>Trupps unter Atemschutz</span><strong>{{ $teams }}</strong></div>
    <div class="ar-tile"><span>Geräteträger im Einsatz/Übung</span><strong>{{ $people }}</strong></div>
    @if($pool)
        <div class="ar-tile"><span>Pool heute</span><strong>{{ array_sum($pool) }}</strong></div>
        <div class="ar-tile"><span>davon einsatzbereit</span><strong>{{ $pool['ready'] + $pool['soon'] }}</strong></div>
    @endif
</div>
@if($trainings->isNotEmpty())
    <table><tbody>@foreach($trainings as $label => $count)<tr><td>{{ $label }} (eingetragene Nachweise)</td><td class="n">{{ $count }}</td></tr>@endforeach</tbody></table>
@endif

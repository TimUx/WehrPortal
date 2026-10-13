{{-- Jahresbericht: Überwachungen, Trupps and Nachweise of the year; the pool as of today in the current year --}}
<div class="ar-grid">
    @foreach($operations as $label => $count)<div class="ar-tile"><span>Überwachungen {{ $label }}</span><strong>{{ $count }}</strong></div>@endforeach
    <div class="ar-tile"><span>Trupps unter Atemschutz</span><strong>{{ $teams }}</strong></div>
    <div class="ar-tile"><span>Geräteträger im Einsatz/Übung</span><strong>{{ $people }}</strong></div>
    @if($pool)
        <div class="ar-tile"><span>Pool heute</span><strong>{{ $pool['ready'] + $pool['soon'] + $pool['not_ready'] }}</strong></div>
        <div class="ar-tile"><span>davon einsatzbereit</span><strong>{{ $pool['ready'] + $pool['soon'] }}</strong></div>
        @if($pool['csa_ready'])<div class="ar-tile"><span>CSA-Träger einsatzbereit</span><strong>{{ $pool['csa_ready'] }}</strong></div>@endif
    @endif
</div>
@if($trainings->isNotEmpty())
    <table><tbody>@foreach($trainings as $label => $count)<tr><td>{{ $label }} (eingetragene Nachweise)</td><td class="n">{{ $count }}</td></tr>@endforeach</tbody></table>
@endif
@if(array_sum($wear) > 0)
    <table><tbody>@foreach($wear as $kind => $minutes)@if($minutes)<tr><td>Tragezeit {{ \Modules\Atemschutz\src\Services\ReadinessService::WEAR_KINDS[$kind] }}</td><td class="n">{{ number_format($minutes / 60, 1, ',', '.') }} h</td></tr>@endif @endforeach</tbody></table>
@endif

{{-- Jahresbericht: hours of the Wehr by source --}}
<div class="ar-grid"><div class="ar-tile"><span>Geleistete Stunden</span><strong>{{ number_format($total / 60, 0, ',', '.') }}</strong></div><div class="ar-tile"><span>Kameraden</span><strong>{{ $people }}</strong></div></div>
<table><tbody>@foreach($bySource as $source => $minutes)<tr><td>{{ $source }}</td><td class="n">{{ number_format($minutes / 60, 1, ',', '.') }} h</td></tr>@endforeach</tbody></table>

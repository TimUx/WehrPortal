{{-- Jahresbericht: Unterweisungen held in the year --}}
<div class="ar-grid"><div class="ar-tile"><span>Unterweisungen</span><strong>{{ $sessions }}</strong></div><div class="ar-tile"><span>Teilnahmen</span><strong>{{ $attendances }}</strong></div></div>
<table><tbody>@foreach($byTopic as $name => $count)<tr><td>{{ $name }}</td><td class="n">{{ $count }}</td></tr>@endforeach</tbody></table>

{{-- Jahresbericht: Lehrgänge completed in the year --}}
<div class="ar-grid"><div class="ar-tile"><span>Absolvierte Lehrgänge</span><strong>{{ $courses->sum() }}</strong></div><div class="ar-tile"><span>Kameraden</span><strong>{{ $people }}</strong></div></div>
<table><tbody>@foreach($courses as $name => $count)<tr><td>{{ $name }}</td><td class="n">{{ $count }}</td></tr>@endforeach</tbody></table>

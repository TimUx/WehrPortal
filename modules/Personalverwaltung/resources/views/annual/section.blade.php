{{-- Jahresbericht: members, from the career --}}
<div class="ar-grid">
    <div class="ar-tile"><span>Aktive am {{ $a['end']->format('d.m.Y') }}</span><strong>{{ $a['active'] }}</strong></div>
    <div class="ar-tile"><span>Eintritte</span><strong>{{ $a['joined']->count() }}</strong></div>
    <div class="ar-tile"><span>Austritte</span><strong>{{ $a['left']->count() }}</strong></div>
    <div class="ar-tile"><span>Beförderungen</span><strong>{{ $a['promotions']->count() }}</strong></div>
    <div class="ar-tile"><span>Ehrungen</span><strong>{{ $a['honors']->count() }}</strong></div>
    @if($a['attendance'] !== null)<div class="ar-tile"><span>Ø Dienstbeteiligung</span><strong>{{ round($a['attendance']) }} %</strong></div>@endif
</div>
@if($a['byGroup']->isNotEmpty())
    <h3>Aktive nach Abteilung</h3>
    <table><tbody>@foreach($a['byGroup'] as $label => $count)<tr><td>{{ $label }}</td><td class="n">{{ $count }}</td></tr>@endforeach</tbody></table>
@endif
@if($a['joined']->isNotEmpty())
    <h3>Eintritte</h3>
    <table><tbody>@foreach($a['joined'] as $u)<tr><td>{{ $u->name }}</td><td class="n">{{ $u->joined_at->format('d.m.Y') }}</td></tr>@endforeach</tbody></table>
@endif
@if($a['promotions']->isNotEmpty())
    <h3>Beförderungen</h3>
    <table><tbody>@foreach($a['promotions'] as $e)<tr><td>{{ $e->user?->name }}</td><td>{{ $e->label }}</td><td class="n">{{ $e->since?->format('d.m.Y') }}</td></tr>@endforeach</tbody></table>
@endif
@if($a['honors']->isNotEmpty())
    <h3>Ehrungen</h3>
    <table><tbody>@foreach($a['honors'] as $h)<tr><td>{{ $h->user?->name }}</td><td>{{ $h->title }}</td><td class="n">{{ $h->awarded_on->format('d.m.Y') }}</td></tr>@endforeach</tbody></table>
@endif
@if($a['left']->isNotEmpty())
    <h3>Austritte</h3>
    <table><tbody>@foreach($a['left'] as $e)<tr><td>{{ $e->user?->name }}</td><td class="n">{{ $e->since?->format('d.m.Y') }}</td></tr>@endforeach</tbody></table>
@endif
<p class="note">Aus der Laufbahn in der Personalverwaltung; sie beginnt mit der Einführung des Moduls.</p>

{{-- CSA status of a CSA-Träger: $csa from ReadinessService (state['csa']); $plain for pages without Tailwind --}}
@if($csa)
    @php($u = $csa['items']['csa_uebung']['until'])
    @if($plain ?? false)
        <p style="font-weight:600;color:{{ $csa['ready'] ? '#065f46' : '#991b1b' }}">CSA: {{ $csa['ready'] ? 'einsatzbereit' : 'nicht einsatzbereit – '.implode(', ', $csa['reasons']) }}</p>
        <dl><dt>CSA-Übung</dt><dd>{{ $u ? ($csa['items']['csa_uebung']['ok'] ? 'bis ' : 'abgelaufen ').$u->format('d.m.Y') : 'fehlt' }}</dd></dl>
    @else
        <p class="text-sm {{ $csa['ready'] ? ($csa['soon'] ? 'text-amber-800' : 'text-green-700') : 'text-red-700' }}">
            <span class="font-semibold">CSA-Träger: {{ $csa['ready'] ? ($csa['soon'] ? 'einsatzbereit – CSA-Übung läuft bald ab' : 'einsatzbereit') : 'nicht einsatzbereit' }}</span>
            <span class="block text-xs">{{ $csa['ready'] ? '' : implode(', ', $csa['reasons']).' · ' }}CSA-Übung {{ $u ? ($csa['items']['csa_uebung']['ok'] ? 'bis ' : 'abgelaufen am ').$u->format('d.m.Y') : 'fehlt' }}@if($csa['items']['csa']['last']) · Ausbildung {{ $csa['items']['csa']['last']->format('d.m.Y') }}@endif</span>
        </p>
    @endif
@endif

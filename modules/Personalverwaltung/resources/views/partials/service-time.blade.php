{{-- The anrechenbare Dienstzeit of $member and what went into it --}}
@php($st = \Modules\Personalverwaltung\src\Support\ServiceTime::on($member))
@if($st)
    @php($y = fn (int $days) => $days >= 365 ? number_format($days / 365.25, 1, ',', '.').' Jahre' : $days.' Tage')
    <div class="rounded-xl bg-gray-50 px-3 py-2 text-sm">
        <p><span class="font-semibold text-gray-900">Anrechenbare Dienstzeit: {{ $st['years'] }} {{ $st['years'] === 1 ? 'Jahr' : 'Jahre' }}{{ $st['months'] ? ', '.$st['months'].' '.($st['months'] === 1 ? 'Monat' : 'Monate') : '' }}</span>
            <span class="text-gray-500">· ab {{ $st['start']->format('d.m.Y') }}</span></p>
        @if($st['gaps'] || $st['other'] || $st['youth'])
            <p class="text-xs text-gray-600">{{ collect([
                $st['other'] ? 'davon '.$y($st['other']).' in anderen Wehren' : null,
                $st['gaps'] ? $y($st['gaps']).' Unterbrechung nicht gezählt' : null,
                $st['youth'] ? $y($st['youth']).' Jugendfeuerwehr nicht angerechnet' : null,
            ])->filter()->implode(' · ') }}</p>
        @endif
        @foreach($st['notes'] as $note)<p class="text-xs text-amber-800">{{ $note }}</p>@endforeach
    </div>
@endif

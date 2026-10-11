@php($select = 'rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-fw-red focus:ring-fw-red')
@php($money = fn ($c) => \App\Support\Money::format($c))
<div class="space-y-4">
    <div>
        <h1 class="text-xl font-bold text-gray-900">Prüfprotokolle</h1>
        <p class="text-sm text-gray-500">Alle unterschriebenen Protokolle – wer hat wann mit welcher Prüfer-ID geprüft. Jedes als PDF für DGUV-Nachweise und Versicherungen.</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <select wire:model.live="year" class="{{ $select }}" aria-label="Jahr"><option value="">Alle Jahre</option>@foreach($years as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach</select>
        <select wire:model.live="result" class="{{ $select }}" aria-label="Ergebnis">
            <option value="">Alle</option>
            @foreach(\Modules\Wartung\src\Models\Protocol::RESULTS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            <option value="voided">Storniert</option>
        </select>
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Gerät, Prüfer, Prüfer-ID, Firma" class="{{ $select }} w-full sm:w-64">
    </div>
    @if($totals)
        <p class="text-sm text-gray-600">{{ $year }}: <strong>{{ $totals['count'] }}</strong> Protokolle, davon {{ $totals['failed'] }} nicht bestanden · Aufwand <strong>{{ intdiv($totals['minutes'], 60) }} h {{ $totals['minutes'] % 60 }} min</strong> · Kosten <strong>{{ $money($totals['cost']) }}</strong></p>
    @endif
    <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
        @forelse($protocols as $p)
            <a href="{{ route('wartung.protocol', $p) }}" class="grid grid-cols-[1fr_auto] items-center gap-x-3 gap-y-1 p-3 text-sm hover:bg-gray-50" wire:key="p-{{ $p->id }}">
                <span class="min-w-0">
                    <span class="block font-medium {{ $p->isVoided() ? 'text-gray-400 line-through' : 'text-gray-900' }}">{{ $p->title }} – {{ $p->equipment_code }} {{ $p->equipment_name }}</span>
                    <span class="block text-xs text-gray-500">{{ $p->code() }} · {{ $p->performed_on->format('d.m.Y') }} · {{ $p->performerLabel() }}{{ $p->inspector_number ? ' (ID '.$p->inspector_number.')' : '' }}</span>
                </span>
                <span class="text-xs font-semibold {{ $p->passed() ? 'text-green-700' : 'text-red-700' }}">{{ \Modules\Wartung\src\Models\Protocol::RESULTS[$p->result] }}</span>
            </a>
        @empty
            <p class="p-4 text-sm text-gray-500">Keine Protokolle.</p>
        @endforelse
    </div>
    {{ $protocols->links() }}
</div>

@php($select = 'rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-fw-red focus:ring-fw-red')
@php($n = fn ($v) => number_format((int) $v, 0, ',', '.'))
<div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Fahrtenbuch {{ $vehicle->name }}</h1>
            <p class="text-sm text-gray-500">{{ $year }}: {{ $n($km) }} km · Kilometerstand {{ $lastKm !== null ? $n($lastKm).' km' : '–' }}@if($per100) · Ø {{ str_replace('.', ',', (string) $per100) }} l/100 km @endif</p>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <select wire:model.live="year" class="{{ $select }}" aria-label="Jahr">@foreach($years as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach</select>
            @if($vehicle->is_active)<a href="{{ route('fahrtenbuch.trip', ['fahrzeug' => $vehicle->id]) }}" class="rounded-lg bg-fw-navy px-3 py-1.5 font-semibold text-white">Fahrt beginnen</a>@endif
            <a href="{{ route('fahrtenbuch.fuel', ['fahrzeug' => $vehicle->id]) }}" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 font-semibold text-gray-700">Tanken</a>
        </div>
    </div>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @if($canManage)
        <p class="flex flex-wrap gap-3 text-xs">
            <a href="{{ route('fahrtenbuch.export.trips', [$vehicle, $year]) }}" class="text-fw-navy underline">Fahrten als CSV</a>
            <a href="{{ route('fahrtenbuch.export.fuel', [$vehicle, $year]) }}" class="text-fw-navy underline">Tanken als CSV</a>
            <a href="{{ route('fahrtenbuch.export.print', [$vehicle, $year]) }}" target="_blank" class="text-fw-navy underline">Drucken / PDF</a>
        </p>
    @endif

    <div class="overflow-x-auto rounded-2xl border border-gray-100 bg-white shadow-sm">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs text-gray-500">
                <tr><th class="p-2">Datum</th><th class="p-2">km</th><th class="p-2">Strecke</th><th class="p-2">Start → Ziel</th><th class="p-2">Zweck</th><th class="p-2">Fahrer</th><th class="p-2"></th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($trips as $t)
                    <tr wire:key="t-{{ $t->id }}" class="{{ $t->voided_at ? 'text-gray-400 line-through' : '' }}">
                        <td class="whitespace-nowrap p-2">{{ $t->started_at->setTimezone('Europe/Berlin')->format('d.m. H:i') }}{{ $t->ended_at ? '–'.$t->ended_at->setTimezone('Europe/Berlin')->format($t->ended_at->isSameDay($t->started_at) ? 'H:i' : 'd.m. H:i') : '' }}</td>
                        <td class="whitespace-nowrap p-2">{{ $n($t->start_km) }} – {{ $t->end_km !== null ? $n($t->end_km) : '…' }}
                            @if(isset($gaps[$t->id]))<span class="block text-xs font-semibold text-amber-700 no-underline">Lücke {{ $n($gaps[$t->id]) }} km davor</span>@endif</td>
                        <td class="whitespace-nowrap p-2 font-semibold">{{ $t->distance() !== null ? $n($t->distance()).' km' : 'unterwegs' }}</td>
                        <td class="p-2">{{ $t->origin }} → {{ $t->destination ?? '…' }}</td>
                        <td class="p-2">{{ $t->purposeLabel() }}{{ $t->purpose_note ? ': '.$t->purpose_note : '' }}{{ $t->report ? ' · Einsatz '.$t->report->number : '' }}</td>
                        <td class="p-2">{{ $t->driver->name }}</td>
                        <td class="whitespace-nowrap p-2 text-xs">
                            @if($t->isOpen())<a href="{{ route('fahrtenbuch.trip', ['fahrt' => $t->id]) }}" class="font-semibold text-fw-red underline">beenden</a>
                            @elseif($canManage)<a href="{{ route('fahrtenbuch.trip', ['fahrt' => $t->id, 'korrigieren' => 1]) }}" class="text-fw-navy underline">korrigieren</a>
                            @else<a href="{{ route('fahrtenbuch.trip', ['fahrt' => $t->id]) }}" class="text-gray-500 underline">ansehen</a>@endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="p-4 text-gray-500">Keine Fahrten in {{ $year }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <section class="space-y-2">
        <p class="text-sm font-semibold text-gray-900">Tanken {{ $year }}</p>
        <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
            @forelse($fuel as $f)
                <div class="flex flex-wrap items-center justify-between gap-2 p-3 text-sm" wire:key="f-{{ $f->id }}">
                    <span>{{ $f->fueled_at->setTimezone('Europe/Berlin')->format('d.m.Y') }} · {{ $f->fuelLabel() }} {{ $f->liters() }}{{ $f->full_tank ? ' (voll)' : '' }}{{ $f->km ? ' · '.$n($f->km).' km' : '' }}<span class="text-xs text-gray-500"> · {{ $f->user?->name }}{{ $f->station ? ' · '.$f->station : '' }}</span></span>
                    <span class="flex items-center gap-3">{{ $f->cost() }}@if($f->receipt_mime)<a href="{{ route('fahrtenbuch.receipt', $f) }}" target="_blank" class="text-xs text-fw-navy underline">Beleg</a>@endif</span>
                </div>
            @empty
                <p class="p-3 text-sm text-gray-500">Kein Tanken eingetragen.</p>
            @endforelse
        </div>
        @if($consumption->isNotEmpty())
            <p class="text-xs text-gray-500">Verbrauch (voll bis voll): @foreach($consumption as $c){{ $c['to']->fueled_at->setTimezone('Europe/Berlin')->format('d.m.') }} {{ str_replace('.', ',', (string) $c['per100']) }} l/100 km{{ $loop->last ? '' : ' · ' }}@endforeach</p>
        @endif
    </section>
</div>

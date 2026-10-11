@php($select = 'rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-fw-red focus:ring-fw-red')
@php($n = fn ($v) => number_format((float) $v, 0, ',', '.'))
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-bold text-gray-900">Auswertung Fahrtenbuch</h1>
        <select wire:model.live="year" class="{{ $select }}" aria-label="Jahr">@foreach(range(now('Europe/Berlin')->year, now('Europe/Berlin')->year - 5) as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach</select>
    </div>
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm"><p class="text-xs text-gray-500">Fahrten</p><p class="text-2xl font-bold">{{ $n($totals['trips']) }}</p></div>
        <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm"><p class="text-xs text-gray-500">Kilometer</p><p class="text-2xl font-bold">{{ $n($totals['km']) }}</p></div>
        <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm"><p class="text-xs text-gray-500">Kraftstoff</p><p class="text-2xl font-bold">{{ $n($totals['liters']) }} l</p></div>
        <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm"><p class="text-xs text-gray-500">Tankkosten</p><p class="text-2xl font-bold">{{ \App\Support\Money::format((int) $totals['cost']) }}</p></div>
    </div>
    <div class="overflow-x-auto rounded-2xl border border-gray-100 bg-white shadow-sm">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs text-gray-500">
                <tr><th class="p-2">Fahrzeug</th><th class="p-2 text-right">Fahrten</th><th class="p-2 text-right">km</th>@foreach($purposes as $p)<th class="p-2 text-right">{{ \Modules\Fahrtenbuch\src\Models\Trip::PURPOSES[$p] ?? $p }}</th>@endforeach<th class="p-2 text-right">Liter</th><th class="p-2 text-right">Kosten</th><th class="p-2 text-right">l/100 km</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($rows as $r)
                    <tr>
                        <td class="p-2"><a href="{{ route('fahrtenbuch.vehicle', ['vehicle' => $r['vehicle'], 'jahr' => $year]) }}" class="text-fw-navy underline">{{ $r['vehicle']->name }}</a></td>
                        <td class="p-2 text-right">{{ $r['trips'] }}</td><td class="p-2 text-right font-semibold">{{ $n($r['km']) }}</td>
                        @foreach($purposes as $p)<td class="p-2 text-right">{{ isset($r['by_purpose'][$p]) ? $n($r['by_purpose'][$p]) : '–' }}</td>@endforeach
                        <td class="p-2 text-right">{{ $n($r['liters']) }}</td><td class="p-2 text-right">{{ \App\Support\Money::format($r['cost']) }}</td>
                        <td class="p-2 text-right">{{ $r['per100'] !== null ? str_replace('.', ',', (string) $r['per100']) : '–' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="p-4 text-gray-500">Keine Fahrten in {{ $year }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="text-xs text-gray-500">km nach Zweck aus abgeschlossenen, nicht stornierten Fahrten mit Beginn im Jahr. Verbrauch aus Tankvorgängen „voll bis voll“ mit Kilometerstand.</p>
</div>

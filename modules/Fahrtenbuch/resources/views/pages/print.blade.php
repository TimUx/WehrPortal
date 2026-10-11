@inject('org', \App\Services\OrganizationSettings::class)
@php($n = fn ($v) => number_format((int) $v, 0, ',', '.'))
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Fahrtenbuch {{ $vehicle->name }} {{ $year }} – {{ $org->shortName() }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: ui-sans-serif, system-ui, sans-serif; color: #111827; margin: 1.5rem; font-size: .8rem; }
        h1 { font-size: 1.3rem; margin: 0; }
        .meta { color: #4b5563; margin: .25rem 0 1rem; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #9ca3af; padding: .25rem .4rem; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; }
        td.num { text-align: right; white-space: nowrap; }
        tr.voided td { color: #9ca3af; text-decoration: line-through; }
        tr { break-inside: avoid; }
        .bar { margin-bottom: 1rem; }
        .bar button { padding: .5rem 1rem; border-radius: .5rem; border: 0; background: #ED2F12; color: #fff; font-weight: 600; cursor: pointer; }
        @page { size: A4 landscape; margin: 1cm; }
        @media print { .bar { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <div class="bar"><button type="button" onclick="window.print()">Drucken / als PDF speichern</button></div>
    <h1>Fahrtenbuch {{ $vehicle->name }} – {{ $year }}</h1>
    <p class="meta">{{ $org->name() }} · {{ $trips->whereNull('voided_at')->count() }} Fahrten, {{ $n($trips->whereNull('voided_at')->sum(fn ($t) => $t->distance())) }} km · Stand {{ now('Europe/Berlin')->format('d.m.Y H:i') }}</p>
    <table>
        <tr><th>Datum</th><th>Zeit</th><th class="num">km Beginn</th><th class="num">km Ende</th><th class="num">Strecke</th><th>Start</th><th>Ziel</th><th>Zweck / Anlass</th><th>Einsatz</th><th>Fahrer</th></tr>
        @forelse($trips as $t)
            <tr class="{{ $t->voided_at ? 'voided' : '' }}">
                <td>{{ $t->started_at->setTimezone('Europe/Berlin')->format('d.m.Y') }}</td>
                <td>{{ $t->started_at->setTimezone('Europe/Berlin')->format('H:i') }}–{{ $t->ended_at?->setTimezone('Europe/Berlin')->format('H:i') ?? 'offen' }}</td>
                <td class="num">{{ $n($t->start_km) }}</td><td class="num">{{ $t->end_km !== null ? $n($t->end_km) : '' }}</td><td class="num">{{ $t->distance() !== null ? $n($t->distance()) : '' }}</td>
                <td>{{ $t->origin }}</td><td>{{ $t->destination }}</td><td>{{ $t->purposeLabel() }}{{ $t->purpose_note ? ': '.$t->purpose_note : '' }}{{ $t->voided_at ? ' (storniert: '.$t->void_reason.')' : '' }}</td>
                <td>{{ $t->report?->number }}</td><td>{{ $t->driver->name }}</td>
            </tr>
        @empty
            <tr><td colspan="10">Keine Fahrten.</td></tr>
        @endforelse
    </table>
    @if($consumption->isNotEmpty())
        <p class="meta">Verbrauch (voll bis voll): {{ str_replace('.', ',', (string) round($consumption->sum('liters') / max(1, $consumption->sum('km')) * 100, 1)) }} l/100 km über {{ $n($consumption->sum('km')) }} km.</p>
    @endif
</body>
</html>

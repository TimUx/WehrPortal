@inject('org', \App\Services\OrganizationSettings::class)
@php($money = fn ($c) => \App\Support\Money::format($c))
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Jahresabschluss {{ $year }} – {{ $org->shortName() }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: ui-sans-serif, system-ui, sans-serif; color: #111827; margin: 1.5rem; }
        h1 { font-size: 1.4rem; margin: 0; }
        h2 { font-size: 1.05rem; margin: 1.5rem 0 .5rem; }
        .meta { color: #4b5563; margin: .25rem 0 1rem; font-size: .9rem; }
        table { width: 100%; border-collapse: collapse; font-size: .85rem; }
        th, td { border: 1px solid #9ca3af; padding: .35rem .5rem; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        tfoot td { font-weight: 700; }
        tr { break-inside: avoid; }
        .bar { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: 1rem; }
        .bar a, .bar button { padding: .5rem 1rem; border-radius: .5rem; border: 1px solid #d1d5db; background: #fff; font-size: .9rem; color: #111827; text-decoration: none; cursor: pointer; }
        .bar button { background: #ED2F12; border-color: #ED2F12; color: #fff; font-weight: 600; }
        .sign { margin-top: 3rem; display: flex; gap: 4rem; font-size: .85rem; }
        .sign div { border-top: 1px solid #111827; padding-top: .25rem; min-width: 14rem; }
        @page { size: A4; margin: 1.2cm; }
        @media print { .bar { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <div class="bar">
        <button type="button" onclick="window.print()">Drucken / als PDF speichern</button>
        <a href="{{ route('finanzen.report', ['jahr' => $year]) }}">Zurück</a>
    </div>

    <h1>Jahresabschluss {{ $year }} – {{ $org->name() }}</h1>
    <p class="meta">{{ $closed ? 'Abgeschlossen' : 'Vorläufig (Jahr noch nicht abgeschlossen)' }} · Stand {{ now('Europe/Berlin')->format('d.m.Y H:i') }}</p>

    <h2>Budget und Ist je Kostenstelle</h2>
    <table>
        <thead><tr><th>Kostenstelle</th><th class="num">Budget</th><th class="num">Ausgegeben</th><th class="num">Genehmigt, offen</th><th class="num">Rest</th><th class="num">Einnahmen</th></tr></thead>
        <tbody>
            @foreach($overview as $row)
                <tr><td>{{ $row['center']->label() }}</td><td class="num">{{ $money($row['budget']) }}</td><td class="num">{{ $money($row['paid']) }}</td><td class="num">{{ $money($row['approved']) }}</td><td class="num">{{ $money($row['available']) }}</td><td class="num">{{ $money($row['income']) }}</td></tr>
            @endforeach
        </tbody>
        <tfoot><tr><td>Summe</td><td class="num">{{ $money($totals['budget']) }}</td><td class="num">{{ $money($totals['paid']) }}</td><td class="num">{{ $money($totals['approved']) }}</td><td class="num">{{ $money($totals['available']) }}</td><td class="num">{{ $money($totals['income']) }}</td></tr></tfoot>
    </table>
    <p class="meta">Einnahmen {{ $money($totals['income']) }} − Ausgaben {{ $money($totals['paid'] + $totals['approved']) }} = <strong>Saldo {{ $money($totals['income'] - $totals['paid'] - $totals['approved']) }}</strong></p>

    <h2>Buchungen</h2>
    <table>
        <thead><tr><th>Beleg</th><th>Datum</th><th>Kostenstelle</th><th>Bezeichnung</th><th>Status</th><th class="num">Einnahme</th><th class="num">Ausgabe</th></tr></thead>
        <tbody>
            @forelse($entries as $e)
                <tr><td>{{ $e->code() }}</td><td>{{ $e->booked_on->format('d.m.Y') }}</td><td>{{ $e->costCenter->name }}</td><td>{{ $e->title }}{{ $e->vendor ? ' ('.$e->vendor.')' : '' }}</td><td>{{ $e->statusLabel() }}</td>
                    <td class="num">{{ $e->isIncome() ? $e->amount() : '' }}</td><td class="num">{{ $e->isIncome() ? '' : $e->amount() }}</td></tr>
            @empty
                <tr><td colspan="7">Keine Buchungen.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="sign"><div>Kassenwart</div><div>Kassenprüfer</div><div>Wehrführung</div></div>
</body>
</html>

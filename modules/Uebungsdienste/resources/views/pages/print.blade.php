@inject('org', \App\Services\OrganizationSettings::class)
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Übungsdienstplan {{ $year }} – {{ $org->shortName() }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: ui-sans-serif, system-ui, sans-serif; color: #111827; margin: 1.5rem; }
        h1 { font-size: 1.4rem; margin: 0; }
        .meta { color: #4b5563; margin: .25rem 0 1rem; font-size: .9rem; }
        table { width: 100%; border-collapse: collapse; font-size: .9rem; }
        th, td { border: 1px solid #9ca3af; padding: .4rem .5rem; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; }
        td.date { white-space: nowrap; font-weight: 600; }
        tr { break-inside: avoid; }
        .bar { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: 1rem; }
        .bar a, .bar button { padding: .5rem 1rem; border-radius: .5rem; border: 1px solid #d1d5db; background: #fff; font-size: .9rem; color: #111827; text-decoration: none; cursor: pointer; }
        .bar button { background: #ED2F12; border-color: #ED2F12; color: #fff; font-weight: 600; }
        @page { size: A4; margin: 1.2cm; }
        @media print { .bar { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <div class="bar">
        <button type="button" onclick="window.print()">Drucken / als PDF speichern</button>
        <a href="{{ route('uebungsdienste.print', ['jahr' => $year, 'kommende' => $upcoming ? 0 : 1]) }}">{{ $upcoming ? 'Ganzes Jahr' : 'Nur kommende Dienste' }}</a>
        <a href="{{ route('uebungsdienste.index', ['jahr' => $year]) }}">Zurück</a>
    </div>

    <h1>Übungsdienstplan {{ $year }}</h1>
    <p class="meta">{{ $org->name() }} · {{ $upcoming ? 'kommende Dienste' : 'ganzes Jahr' }} · Stand {{ now('Europe/Berlin')->format('d.m.Y') }}</p>

    <table>
        <thead><tr><th>Datum</th><th>Beginn</th><th>Thema</th><th>Ort</th><th>Verantwortlich</th></tr></thead>
        <tbody>
            @forelse($services as $service)
                <tr>
                    <td class="date">{{ $service->dateLabel() }}</td>
                    <td>{{ $service->starts_at ? $service->starts_at.' Uhr' : '' }}</td>
                    <td style="white-space: pre-line">{{ $service->topic }}</td>
                    <td>{{ $service->location }}</td>
                    <td>{{ $service->responsible->pluck('name')->implode(', ') }}</td>
                </tr>
            @empty
                <tr><td colspan="5">Keine Dienste.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>

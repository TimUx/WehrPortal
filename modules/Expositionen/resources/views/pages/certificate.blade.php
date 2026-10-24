@php($org = app(\App\Services\OrganizationSettings::class))
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Expositionsnachweis {{ $member->name }}</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; color: #111827; max-width: 60rem; margin: 2rem auto; padding: 0 1rem; font-size: 14px; }
        h1 { font-size: 1.35rem; margin: 0 0 .25rem; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { text-align: left; vertical-align: top; padding: .4rem; border-bottom: 1px solid #e5e7eb; }
        th { font-size: .75rem; color: #6b7280; text-transform: uppercase; }
        .muted { color: #6b7280; font-size: .85rem; }
        @media print { .noprint { display: none; } body { margin: 0 auto; } }
    </style>
</head>
<body>
    <p class="noprint"><button type="button" onclick="window.print()">Drucken</button></p>
    <h1>Nachweis über Gefahrstoff-Expositionen</h1>
    <p>{{ $member->name }}@if($member->birth_date), geboren am {{ $member->birth_date->format('d.m.Y') }}@endif · {{ $org->name() }}</p>
    <p class="muted">Nach § 14 Gefahrstoffverordnung werden Tätigkeiten mit krebserzeugenden, keimzellmutagenen oder reproduktionstoxischen Gefahrstoffen dokumentiert und 40 Jahre aufbewahrt. Dieser Auszug ist für die Einsatzkraft bestimmt – z. B. beim Ausscheiden aus der Feuerwehr oder für den Arzt.</p>
    <table>
        <thead><tr><th>Datum</th><th>Anlass</th><th>Tätigkeit</th><th>Gefahrstoffe</th><th>Dauer</th><th>Aufnahme</th><th>Schutz</th><th>ZED</th></tr></thead>
        <tbody>
            @forelse($exposures as $e)
                <tr><td>{{ $e->occurred_on->format('d.m.Y') }}</td><td>{{ $e->occasion }}</td><td>{{ $e->activityLabel() }}</td><td>{{ $e->substanceList() }}</td>
                    <td>{{ $e->duration_minutes ? $e->duration_minutes.' min' : '–' }}</td><td>{{ $e->routeList() ?: '–' }}</td><td>{{ $e->protectionList() ?: '–' }}</td>
                    <td>{{ $e->reported_on ? 'gemeldet '.$e->reported_on->format('d.m.Y') : 'noch nicht' }}</td></tr>
            @empty
                <tr><td colspan="8">Keine Expositionen erfasst.</td></tr>
            @endforelse
        </tbody>
    </table>
    <p class="muted">Erstellt am {{ now('Europe/Berlin')->format('d.m.Y') }} aus WehrPortal.</p>
</body>
</html>

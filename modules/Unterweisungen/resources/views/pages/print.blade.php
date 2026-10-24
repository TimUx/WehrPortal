@php($org = app(\App\Services\OrganizationSettings::class))
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
    <title>Unterweisungsnachweis {{ $session->topic->name }}</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; color: #111827; max-width: 52rem; margin: 2rem auto; padding: 0 1rem; font-size: 14px; }
        h1 { font-size: 1.35rem; margin: 0 0 .25rem; } table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { text-align: left; padding: .4rem; border-bottom: 1px solid #e5e7eb; vertical-align: middle; } th { font-size: .75rem; color: #6b7280; }
        td img { height: 2.6rem; } .muted { color: #6b7280; font-size: .85rem; } @media print { .noprint { display: none; } }
    </style>
</head>
<body>
    <p class="noprint"><button type="button" onclick="window.print()">Drucken</button></p>
    <h1>Nachweis über die Unterweisung</h1>
    <p><b>{{ $session->topic->name }}</b>{{ $session->topic->kind === 'einweisung' ? ' (Einweisung)' : '' }} · {{ $session->held_on->format('d.m.Y') }} · {{ $org->name() }}</p>
    <p>Unterwiesen von: {{ $session->instructor_name }}</p>
    @if($session->topic->description)<p class="muted">{{ $session->topic->description }}</p>@endif
    @if($session->notes)<p class="muted" style="white-space:pre-line">{{ $session->notes }}</p>@endif
    <table>
        <thead><tr><th>Name</th><th>Unterschrift</th><th>am</th></tr></thead>
        <tbody>@foreach($attendances as $a)<tr><td>{{ $a->user?->name }}</td><td>@if($a->signature)<img src="data:image/png;base64,{{ \Modules\Unterweisungen\src\Models\Attendance::whereKey($a->id)->value('signature') }}" alt="Unterschrift">@else <span class="muted">nicht unterschrieben</span>@endif</td><td>{{ $a->signed_at?->setTimezone('Europe/Berlin')->format('d.m.Y') }}</td></tr>@endforeach</tbody>
    </table>
    <p class="muted">Die Teilnehmenden bestätigen mit ihrer Unterschrift, an der Unterweisung teilgenommen und den Inhalt verstanden zu haben. Erstellt am {{ now('Europe/Berlin')->format('d.m.Y') }} aus WehrPortal.</p>
</body>
</html>

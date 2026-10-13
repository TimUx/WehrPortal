<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Schlüsselquittung {{ $handover->key->code }}</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; color: #111827; max-width: 42rem; margin: 2rem auto; padding: 0 1rem; }
        h1 { font-size: 1.4rem; margin: 0 0 .25rem; }
        table { width: 100%; border-collapse: collapse; margin: 1.25rem 0; }
        td { padding: .45rem 0; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        td:first-child { color: #6b7280; width: 40%; }
        .sign { margin-top: 2rem; display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; }
        .line { border-top: 1px solid #111827; padding-top: .25rem; font-size: .85rem; color: #4b5563; }
        .sig { height: 4.5rem; }
        @media print { .noprint { display: none; } body { margin: 0 auto; } }
    </style>
</head>
<body>
    <p class="noprint"><button onclick="window.print()">Drucken / als PDF speichern</button></p>
    <h1>Schlüsselquittung</h1>
    <p>{{ $org }}</p>
    <table>
        <tr><td>Schlüssel</td><td><strong>{{ $handover->key->name }}</strong> ({{ $handover->key->code }}, {{ $handover->key->kindLabel() }})</td></tr>
        @if($handover->key->opensLabel())<tr><td>Schließt</td><td>{{ $handover->key->opensLabel() }}</td></tr>@endif
        <tr><td>Empfangen von</td><td>{{ $handover->user->name }}</td></tr>
        <tr><td>Ausgegeben am</td><td>{{ $handover->issued_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}@if($handover->issuer) durch {{ $handover->issuer->name }}@endif</td></tr>
        @if($handover->due_on)<tr><td>Rückgabe bis</td><td>{{ $handover->due_on->format('d.m.Y') }}</td></tr>@endif
        @if($handover->note)<tr><td>Bemerkung</td><td>{{ $handover->note }}</td></tr>@endif
        @if($handover->returned_at)<tr><td>Zurückgegeben am</td><td>{{ $handover->returned_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}@if($handover->receiver) an {{ $handover->receiver->name }}@endif</td></tr>@endif
    </table>
    <p>Ich habe den Schlüssel erhalten. Ich gebe ihn nicht weiter, lasse ihn nicht nachmachen und melde einen Verlust sofort.</p>
    <div class="sign">
        <div>@if($handover->signature)<img class="sig" src="data:image/png;base64,{{ $handover->signature }}" alt="Unterschrift">@else<div class="sig"></div>@endif<div class="line">Unterschrift {{ $handover->user->name }}</div></div>
        <div><div class="sig"></div><div class="line">Unterschrift Schlüsselwart</div></div>
    </div>
</body>
</html>

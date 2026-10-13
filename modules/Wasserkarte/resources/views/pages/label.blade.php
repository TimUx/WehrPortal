<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Etikett {{ $source->label() }}</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; color: #111827; margin: 2rem auto; padding: 0 1rem; max-width: 42rem; }
        .label { display: inline-flex; align-items: center; gap: 1rem; border: 2px solid #111827; border-radius: .5rem; padding: .75rem 1rem; }
        .label img { width: 7rem; height: 7rem; }
        .label strong { display: block; font-size: 1.2rem; }
        .label span { display: block; font-size: .85rem; color: #374151; }
        @media print { .noprint { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <p class="noprint"><a href="{{ route('wasserkarte.source', $source) }}">← {{ $source->label() }}</a> · <button type="button" onclick="window.print()">Drucken</button></p>
    <p class="noprint" style="color:#4b5563;font-size:.9rem">Wetterfest ausdrucken (z. B. laminiert) und an der Entnahmestelle oder am Hinweisschild anbringen. Wer den Code scannt, landet nach der Anmeldung direkt bei dieser Stelle und kann einen Mangel melden oder – als Hydrantenwart – die Prüfung eintragen.</p>
    <div class="label">
        <img src="{{ \App\Support\QrCode::dataUri(route('scan.code', $source->scanCode()), 220) }}" alt="QR-Code {{ $source->scanCode() }}">
        <div>
            <strong>{{ $source->number ?: $source->scanCode() }}</strong>
            <span>{{ $source->kindLabel() }}</span>
            @if($source->location)<span>{{ $source->location }}</span>@endif
            <span>{{ $source->scanCode() }}</span>
        </div>
    </div>
</body>
</html>

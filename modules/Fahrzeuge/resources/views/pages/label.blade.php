<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Etikett {{ $vehicle->name }}</title>
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
    <p class="noprint"><a href="{{ route('fahrzeuge.show', $vehicle) }}">← {{ $vehicle->name }}</a> · <button type="button" onclick="window.print()">Drucken</button></p>
    <p class="noprint" style="color:#4b5563;font-size:.9rem">Im Fahrzeug anbringen (z. B. Fahrerhaus oder Geräteraum). Wer den Code scannt, landet nach der Anmeldung in der Fahrzeugakte – mit Status, Fristen, Dokumenten und „Mangel melden“.</p>
    <div class="label">
        <img src="{{ \App\Support\QrCode::dataUri(route('scan.code', 'FZ-'.$vehicle->id), 220) }}" alt="QR-Code FZ-{{ $vehicle->id }}">
        <div>
            <strong>{{ $vehicle->name }}</strong>
            @if($profile->call_sign)<span>{{ $profile->call_sign }}</span>@endif
            @if($profile->license_plate)<span>{{ $profile->license_plate }}</span>@endif
            <span>Mangel? Scannen und melden · FZ-{{ $vehicle->id }}</span>
        </div>
    </div>
</body>
</html>

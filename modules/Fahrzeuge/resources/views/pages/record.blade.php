<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Prüfprotokoll {{ $record->vehicle->name }}</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; color: #111827; margin: 2rem auto; padding: 0 1rem; max-width: 48rem; font-size: .9rem; }
        h1 { font-size: 1.3rem; margin: 0 0 .25rem; }
        table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
        th, td { border: 1px solid #d1d5db; padding: .35rem .5rem; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; }
        dl { display: grid; grid-template-columns: 11rem 1fr; gap: .2rem .75rem; margin: 1rem 0; }
        dt { color: #4b5563; }
        .defect { color: #b91c1c; font-weight: 600; }
        .sig img { max-height: 5rem; border-bottom: 1px solid #111827; }
        @media print { .noprint { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <p class="noprint"><a href="{{ route('fahrzeuge.show', $record->vehicle) }}">← {{ $record->vehicle->name }}</a> · <button type="button" onclick="window.print()">Drucken</button></p>
    <h1>Prüfprotokoll: {{ $record->title }}</h1>
    <p>{{ $record->vehicle->name }}{{ $profile->call_sign ? ' · '.$profile->call_sign : '' }}{{ $profile->license_plate ? ' · '.$profile->license_plate : '' }}{{ $profile->vin ? ' · FIN '.$profile->vin : '' }}</p>
    <dl>
        <dt>Datum</dt><dd>{{ $record->done_on->format('d.m.Y') }}</dd>
        <dt>Art</dt><dd>{{ $record->kindLabel() }}</dd>
        @if($record->provider)<dt>Werkstatt / Prüfer</dt><dd>{{ $record->provider }}</dd>@endif
        @if($record->mileage_km)<dt>Kilometerstand</dt><dd>{{ number_format($record->mileage_km, 0, ',', '.') }} km</dd>@endif
        @if($record->result)<dt>Ergebnis</dt><dd class="{{ $record->result === 'passed' ? '' : 'defect' }}">{{ $record->resultLabel() }}</dd>@endif
        @if($record->deadline?->interval_months)<dt>Prüfintervall</dt><dd>alle {{ $record->deadline->interval_months }} Monate</dd>@endif
        <dt>Eingetragen von</dt><dd>{{ $record->creator?->name ?? '–' }}</dd>
    </dl>
    @if($record->checklist)
        <table>
            <thead><tr><th>Prüfschritt</th><th>Befund</th><th>Bemerkung</th></tr></thead>
            <tbody>
                @foreach($record->checklist as $step)
                    <tr><td>{{ $step['step'] }}</td><td class="{{ $step['state'] === 'defect' ? 'defect' : '' }}">{{ \Modules\Fahrzeuge\src\Models\Record::STEP_STATES[$step['state']] ?? $step['state'] }}</td><td>{{ $step['note'] ?? '' }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif
    @if($record->notes)<p style="white-space: pre-line">{{ $record->notes }}</p>@endif
    @if($record->signed_at)
        <div class="sig">
            <img src="data:image/png;base64,{{ $record->signature }}" alt="Unterschrift">
            <p>{{ $record->signer_name }}, {{ $record->signed_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}</p>
        </div>
    @endif
</body>
</html>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Hydrantenkontrolle {{ $year->year }}</title>
    <style>
        @page { margin: 18mm 16mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #222; }
        h1 { font-size: 18pt; } h2 { font-size: 12pt; page-break-after: avoid; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        th, td { border: 1px solid #ccc; padding: 6px; text-align: left; vertical-align: top; word-wrap: break-word; }
        th { background: #eee; } thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .muted { color: #555; } .notes { white-space: pre-wrap; word-wrap: break-word; }
    </style>
</head>
<body>
    <h1>Hydrantenkontrolle {{ $year->year }}</h1>
    <p><strong>{{ $org }}</strong>@if($year->ext_location_id)<br>Standort: {{ $year->location?->name ?? $year->ext_location_id }}@endif</p>
    <p class="muted">Erstellt am {{ $createdAt->format('d.m.Y H:i') }} Uhr (Europe/Berlin).<br>
        Jahresbericht / Zwischenstand – ungeprüfte Hydranten sind ausdrücklich ausgewiesen.</p>
    <table>
        <thead><tr><th>Gesamt</th><th>Ungeprüft</th><th>In Ordnung</th><th>Mit Mängeln</th></tr></thead>
        <tbody><tr>
            <td>{{ $inspections->count() }}</td>
            <td>{{ $inspections->whereNull('checked_at')->count() }}</td>
            <td>{{ $inspections->whereNotNull('checked_at')->where('is_ok', true)->count() }}</td>
            <td>{{ $inspections->whereNotNull('checked_at')->where('is_ok', false)->count() }}</td>
        </tr></tbody>
    </table>
    <h2>Prüfübersicht</h2>
    <table>
        <thead><tr><th>Nr.</th><th>Straße / Lage</th><th>Prüfdatum</th><th>Ergebnis</th></tr></thead>
        <tbody>
            @forelse($inspections as $inspection)
                <tr>
                    <td>{{ $inspection->snapshot['number'] }}</td>
                    <td>{{ $inspection->snapshot['street'] ?? 'Siehe Lagebeschreibung' }} {{ $inspection->snapshot['house_number'] ?? '' }}</td>
                    <td>{{ $inspection->checked_at?->format('d.m.Y') ?? '–' }}</td>
                    <td>{{ $inspection->statusLabel() }}</td>
                </tr>
            @empty
                <tr><td colspan="4">Keine Hydranten in dieser Jahreskontrolle.</td></tr>
            @endforelse
        </tbody>
    </table>
    @foreach($inspections as $inspection)
        <h2>Hydrant Nr. {{ $inspection->snapshot['number'] }}</h2>
        <table>
            <tr><th>Adresse</th><td>{{ $inspection->snapshot['street'] ?? '–' }} {{ $inspection->snapshot['house_number'] ?? '' }}</td></tr>
            <tr><th>Koordinaten</th><td>{{ $inspection->snapshot['latitude'] ?? '–' }} / {{ $inspection->snapshot['longitude'] ?? '–' }}</td></tr>
            <tr><th>Prüfung</th><td>{{ $inspection->checked_at?->format('d.m.Y') ?? 'Ungeprüft' }} · Prüfer: {{ $inspection->inspector_name ?? '–' }}</td></tr>
            <tr><th>Ergebnis</th><td>{{ $inspection->statusLabel() }}</td></tr>
            <tr><th>Mängel</th><td>
                @forelse($inspection->defects ?? [] as $defect)
                    <div>{{ $defects[$defect] ?? $defect }}</div>
                @empty
                    {{ $inspection->checked_at ? 'Keine Mängel' : 'Noch nicht geprüft' }}
                @endforelse
            </td></tr>
        </table>
        <p><strong>Lagebeschreibung</strong></p>
        <div class="notes">{{ $inspection->snapshot['location_description'] ?: '–' }}</div>
        <p><strong>Bemerkungen</strong></p>
        <div class="notes">{{ $inspection->notes ?: '–' }}</div>
    @endforeach
</body>
</html>

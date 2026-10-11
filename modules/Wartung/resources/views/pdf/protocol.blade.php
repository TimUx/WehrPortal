@php($money = fn ($c) => \App\Support\Money::format($c))
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Prüfprotokoll {{ $protocol->code() }}</title>
    <style>
        @page { margin: 1.5cm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #111827; }
        h1 { font-size: 15pt; margin: 0 0 2pt; }
        .meta { color: #4b5563; margin: 0 0 10pt; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10pt; }
        th, td { border: 1px solid #9ca3af; padding: 4pt 5pt; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; }
        .label { width: 32%; background: #f9fafb; font-weight: bold; }
        .passed { color: #15803d; font-weight: bold; }
        .failed { color: #b91c1c; font-weight: bold; }
        .voided { border: 2px solid #b91c1c; color: #b91c1c; padding: 5pt; margin-bottom: 10pt; }
        .sign img { height: 55pt; }
        .small { font-size: 7.5pt; color: #6b7280; word-break: break-all; }
        .photo { display: inline-block; width: 48%; margin: 0 1% 6pt 0; vertical-align: top; }
        .photo img { width: 100%; }
    </style>
</head>
<body>
    <h1>Prüfprotokoll {{ $protocol->code() }}</h1>
    <p class="meta">{{ $org }} · {{ $protocol->title }}</p>

    @if($protocol->isVoided())
        <div class="voided">Storniert am {{ $protocol->voided_at->setTimezone('Europe/Berlin')->format('d.m.Y') }}: {{ $protocol->void_reason }}</div>
    @endif

    <table>
        <tr><td class="label">Gerät</td><td>{{ $protocol->equipment_code }} – {{ $protocol->equipment_name }}</td></tr>
        <tr><td class="label">Prüfung</td><td>{{ $protocol->title }}</td></tr>
        <tr><td class="label">Datum</td><td>{{ $protocol->performed_on->format('d.m.Y') }}</td></tr>
        <tr><td class="label">Ergebnis</td><td class="{{ $protocol->result }}">{{ \Modules\Wartung\src\Models\Protocol::RESULTS[$protocol->result] }}</td></tr>
        <tr><td class="label">Prüfer</td><td>{{ $protocol->inspector_name }}@if($protocol->provider_name) ({{ $protocol->provider_name }})@endif</td></tr>
        @if($protocol->qualification)<tr><td class="label">Qualifikation</td><td>{{ $protocol->qualification }}</td></tr>@endif
        @if($protocol->inspector_number)<tr><td class="label">Prüfer-ID</td><td>{{ $protocol->inspector_number }}</td></tr>@endif
        <tr><td class="label">Zeitaufwand</td><td>{{ $protocol->minutes }} Minuten</td></tr>
        <tr><td class="label">Kosten</td><td>{{ $money($protocol->totalCents()) }} (Arbeit {{ $money($protocol->labor_cost_cents) }}, Material {{ $money($protocol->material_cost_cents) }}@if($protocol->external_cost_cents), Fachfirma {{ $money($protocol->external_cost_cents) }}@endif)</td></tr>
    </table>

    @if($protocol->checklist)
        <table>
            <thead><tr><th>Prüfpunkt</th><th style="width: 18%">Ergebnis</th><th style="width: 35%">Bemerkung</th></tr></thead>
            <tbody>
                @foreach($protocol->checklist as $row)
                    <tr><td>{{ $row['item'] }}</td><td class="{{ $row['result'] === 'defect' ? 'failed' : '' }}">{{ \Modules\Wartung\src\Models\Protocol::ITEM_RESULTS[$row['result']] ?? $row['result'] }}</td><td>{{ $row['note'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($protocol->findings)
        <table><tr><td class="label">Befund / Bemerkungen</td><td>{!! nl2br(e($protocol->findings)) !!}</td></tr></table>
    @endif

    @if($files->isNotEmpty())
        <p>Weitere Anlagen: {{ $files->pluck('name')->implode(', ') }}</p>
    @endif

    <table class="sign">
        <tr>
            <td class="label">Unterschrift Prüfer</td>
            <td><img src="{{ $protocol->signatureDataUrl() }}" alt="Unterschrift"><br>{{ $protocol->inspector_name }}, {{ $protocol->signed_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }} Uhr</td>
        </tr>
    </table>
    <p class="small">Prüfsumme (SHA-256) {{ $protocol->content_hash }} · {{ $intact ? 'Inhalt, Unterschrift und Anlagen unverändert' : 'PRÜFUNG FEHLGESCHLAGEN' }} · erstellt {{ now('Europe/Berlin')->format('d.m.Y H:i') }}</p>

    @foreach($photos as $photo)
        <div class="photo"><img src="{{ $photo['src'] }}" alt=""><div class="small">{{ $photo['name'] }}</div></div>
    @endforeach
</body>
</html>

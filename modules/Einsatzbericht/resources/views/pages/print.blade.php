@inject('org', \App\Services\OrganizationSettings::class)
@php($t = fn ($time) => $time?->copy()->setTimezone('Europe/Berlin')->format('d.m.Y H:i') ?? '–')
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Einsatzbericht {{ $report->number }} – {{ $org->shortName() }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: ui-sans-serif, system-ui, sans-serif; color: #111827; margin: 1.5rem; font-size: .85rem; }
        h1 { font-size: 1.3rem; margin: 0; }
        h2 { font-size: .95rem; margin: 1rem 0 .35rem; border-bottom: 1px solid #9ca3af; padding-bottom: .15rem; }
        .meta { color: #4b5563; margin: .25rem 0 .75rem; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #d1d5db; padding: .3rem .45rem; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; width: 28%; font-weight: 600; }
        .grid th { width: auto; }
        .text { white-space: pre-line; }
        section { break-inside: avoid; }
        .bar { display: flex; gap: .5rem; margin-bottom: 1rem; }
        .bar button, .bar a { padding: .5rem 1rem; border-radius: .5rem; border: 1px solid #d1d5db; background: #fff; color: #111827; text-decoration: none; font-size: .9rem; cursor: pointer; }
        .bar button { background: #ED2F12; border-color: #ED2F12; color: #fff; font-weight: 600; }
        .sign { display: flex; gap: 2rem; margin-top: 2rem; }
        .sign div { flex: 1; border-top: 1px solid #111827; padding-top: .25rem; color: #4b5563; }
        @page { size: A4; margin: 1.2cm; }
        @media print { .bar { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <div class="bar">
        <button type="button" onclick="window.print()">Drucken / als PDF speichern</button>
        <a href="{{ route('einsatzbericht.show', $report) }}">Zurück</a>
    </div>

    <h1>Einsatzbericht {{ $report->number }}</h1>
    <p class="meta">{{ $org->name() }} · {{ $report->statusLabel() }}@if($report->released_at), freigegeben {{ $t($report->released_at) }} von {{ $report->releaser?->name }}@endif</p>

    <section>
        <h2>Kopfdaten und Zeiten</h2>
        <table>
            <tr><th>Einsatzstichwort</th><td>{{ $report->keyword ?: '–' }}</td></tr>
            <tr><th>Einsatznummer Leitstelle</th><td>{{ $report->dispatch_number ?: '–' }}</td></tr>
            <tr><th>Einsatzart</th><td>{{ $report->categoryLabel() }}</td></tr>
            @if($report->alarm_message)<tr><th>Meldung (DIVERA)</th><td class="text">{{ $report->alarm_message }}</td></tr>@endif
            @foreach(\Modules\Einsatzbericht\src\Models\Report::TIMES as $field => $label)
                <tr><th>{{ $label }}</th><td>{{ $t($report->{$field}) }}</td></tr>
            @endforeach
            <tr><th>Dauer</th><td>{{ \Modules\Einsatzbericht\src\Models\Report::formatMinutes($report->durationMinutes()) }}</td></tr>
        </table>
    </section>

    <section>
        <h2>Einsatzstelle</h2>
        <table>
            <tr><th>Adresse</th><td>{{ $report->address ?: '–' }}</td></tr>
            <tr><th>Objekt</th><td>{{ $report->object ?: '–' }}</td></tr>
            @if($report->latitude && $report->longitude)<tr><th>Koordinaten</th><td>{{ $report->latitude }}, {{ $report->longitude }}</td></tr>@endif
            <tr><th>Eigentümer / Betroffene</th><td class="text">{{ $report->affected_parties ?: '–' }}</td></tr>
        </table>
    </section>

    <section>
        <h2>Lage und Maßnahmen</h2>
        <table>
            <tr><th>Lage beim Eintreffen</th><td class="text">{{ $report->situation ?: '–' }}</td></tr>
            <tr><th>Getroffene Maßnahmen</th><td class="text">{{ $report->measures ?: '–' }}</td></tr>
            <tr><th>Eingesetzte Geräte</th><td class="text">{{ $report->equipment_used ?: '–' }}</td></tr>
        </table>
    </section>

    <section>
        <h2>Fahrzeuge und Besatzung · Stärke {{ $strength['label'] }}</h2>
        <table class="grid">
            <tr><th>Fahrzeug</th><th>Zeiten</th><th>Besatzung (Funktion, AS = Atemschutz)</th></tr>
            @forelse($report->vehicles as $vehicle)
                <tr>
                    <td><strong>{{ $vehicle->name }}</strong>@unless($vehicle->is_standby)<br>{{ \Modules\Einsatzbericht\src\Services\ReportService::strength($vehicle->crew)['label'] }}@endunless</td>
                    <td>@unless($vehicle->is_standby)@foreach(\Modules\Einsatzbericht\src\Models\ReportVehicle::TIMES as $field => $label){{ $label }}: {{ $vehicle->{$field}?->setTimezone('Europe/Berlin')->format('H:i') ?? '–' }}<br>@endforeach @else – @endunless</td>
                    <td>{!! $vehicle->crew->map(fn ($c) => e($c->user->name.' ('.$c->functionLabel().($c->breathing_apparatus ? ', AS' : '').')'))->implode('<br>') ?: '–' !!}</td>
                </tr>
            @empty
                <tr><td colspan="3">Keine Fahrzeuge eingetragen.</td></tr>
            @endforelse
        </table>
    </section>

    @if(app(\App\Services\ModuleRegistry::class)->isActive('atemschutz'))
        @include('atemschutz::partials.report-section', ['report' => $report, 'print' => true])
    @endif

    @if($report->materials->isNotEmpty())
        <section>
            <h2>Verbrauchsmaterial</h2>
            <table class="grid">
                <tr><th>Material</th><th>Kategorie</th><th>Menge</th></tr>
                @foreach($report->materials as $item)
                    <tr><td>{{ $item->name }}</td><td>{{ $item->categoryLabel() }}</td><td>{{ \Modules\Einsatzbericht\src\Models\ReportMaterial::amount($item->quantity, $item->unit) }}</td></tr>
                @endforeach
            </table>
        </section>
    @endif

    <section>
        <h2>Personen, Schäden und Beteiligte</h2>
        <table>
            <tr><th>Gerettete / Verletzte / Tote</th><td>{{ $report->rescued }} / {{ $report->injured }} / {{ $report->dead }}</td></tr>
            <tr><th>Sachschäden</th><td class="text">{{ $report->property_damage ?: '–' }}</td></tr>
            <tr><th>Umweltschäden</th><td class="text">{{ $report->environmental_damage ?: '–' }}</td></tr>
            <tr><th>Polizei / Rettungsdienst</th><td>{{ $report->police ? 'ja' : 'nein' }} / {{ $report->ems ? 'ja' : 'nein' }}</td></tr>
            <tr><th>Nachbarwehren</th><td>{{ $report->neighbor_brigades ?: '–' }}</td></tr>
            <tr><th>Sonstige Stellen</th><td>{{ $report->other_parties ?: '–' }}</td></tr>
        </table>
    </section>

    @if($report->externalUnits->isNotEmpty())
        <section>
            <h2>Externe Wehren (QR-Zugang)</h2>
            <table class="grid">
                <tr><th>Wehr · Fahrzeug · Stärke</th><th>Status</th></tr>
                @foreach($report->externalUnits as $unit)
                    <tr><td>{{ $unit->label() }}</td><td>{{ $unit->statuses->sortBy('created_at')->map(fn ($s) => $s->created_at->setTimezone('Europe/Berlin')->format('H:i').' '.$s->label())->implode(' · ') ?: 'angemeldet' }}</td></tr>
                @endforeach
            </table>
        </section>
    @endif

    @if($report->messages->isNotEmpty())
        <section>
            <h2>Lagemeldungen (Einsatztagebuch)</h2>
            <table class="grid">
                <tr><th>Zeit</th><th>Von</th><th>Meldung</th></tr>
                @foreach($report->messages->sortBy('created_at') as $msg)
                    <tr>
                        <td style="white-space: nowrap">{{ $msg->created_at->setTimezone('Europe/Berlin')->format('d.m. H:i') }}{{ $msg->important ? ' !' : '' }}</td>
                        <td>{{ $msg->authorLabel() }}</td>
                        <td><span class="text">{{ $msg->text }}</span>@foreach($msg->photos as $photo)<br><img src="{{ $photo->dataUri() }}" alt="" style="max-width: 6cm; max-height: 4.5cm; margin-top: .25rem">@endforeach</td>
                    </tr>
                @endforeach
            </table>
        </section>
    @endif

    <section>
        <h2>Abschluss</h2>
        <table>
            <tr><th>Übergabe der Einsatzstelle</th><td>{{ $report->handover ?: '–' }}</td></tr>
            <tr><th>Brandwache</th><td>{{ $report->fire_watch ? 'ja'.($report->fire_watch_notes ? ': '.$report->fire_watch_notes : '') : 'nein' }}</td></tr>
            <tr><th>Einsatzleiter</th><td>{{ $report->commander?->name ?? '–' }}</td></tr>
        </table>
        <div class="sign"><div>Einsatzleiter</div><div>Wehrführung</div></div>
    </section>
</body>
</html>

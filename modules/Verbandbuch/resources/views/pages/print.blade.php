@inject('org', \App\Services\OrganizationSettings::class)
@php($time = fn ($t) => $t?->setTimezone('Europe/Berlin')->format('d.m.Y, H:i').' Uhr')
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} – {{ $org->shortName() }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: ui-sans-serif, system-ui, sans-serif; color: #111827; margin: 1.5rem; }
        h1 { font-size: 1.4rem; margin: 0; }
        h2 { font-size: 1.05rem; margin: 0 0 .5rem; }
        .meta { color: #4b5563; margin: .25rem 0 1rem; font-size: .9rem; }
        .entry { border: 1px solid #9ca3af; padding: .75rem; margin-bottom: 1rem; break-inside: avoid; }
        .voided { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; font-size: .85rem; }
        th, td { border: 1px solid #d1d5db; padding: .3rem .5rem; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; width: 32%; font-weight: 600; }
        td { white-space: pre-line; }
        .signs { display: flex; flex-wrap: wrap; gap: 1.5rem; margin-top: .75rem; font-size: .8rem; }
        .signs figure { margin: 0; }
        .signs img { height: 4rem; display: block; border-bottom: 1px solid #111827; }
        .hash { font-size: .7rem; color: #6b7280; word-break: break-all; margin-top: .5rem; }
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
        <a href="{{ $entries->count() === 1 && ! isset($year) ? route('verbandbuch.show', $entries->first()) : route('verbandbuch.index', ['jahr' => $year ?? null]) }}">Zurück</a>
    </div>

    <h1>{{ $title }} – {{ $org->name() }}</h1>
    <p class="meta">Dokumentation der Erste-Hilfe-Leistungen nach DGUV Information 204-021 · Vertraulich: enthält Gesundheitsdaten · Stand {{ now('Europe/Berlin')->format('d.m.Y H:i') }}</p>

    @forelse($entries as $entry)
        <div class="entry {{ $entry->isVoided() ? 'voided' : '' }}">
            <h2>Nr. {{ $entry->code() }} – {{ $entry->kindLabel() }}@if($entry->isVoided()) (storniert)@endif</h2>
            <table>
                <tr><th>Name der verletzten / beteiligten Person</th><td>{{ $entry->injured_name ?? '–' }}</td></tr>
                <tr><th>Datum und Uhrzeit</th><td>{{ $time($entry->occurred_at) }}</td></tr>
                <tr><th>Ort / Unternehmensteil</th><td>{{ $entry->location }} ({{ $entry->occasionLabel() }})</td></tr>
                <tr><th>Hergang</th><td>{{ $entry->description }}</td></tr>
                <tr><th>Art und Umfang der Verletzung</th><td>{{ $entry->injury ?? '–' }}</td></tr>
                <tr><th>Zeugen</th><td>{{ $entry->witnesses ?? '–' }}</td></tr>
                <tr><th>Datum und Uhrzeit der Ersten Hilfe</th><td>{{ $entry->first_aid_at ? $time($entry->first_aid_at) : '–' }}</td></tr>
                <tr><th>Erste-Hilfe-Maßnahmen</th><td>{{ $entry->first_aid_measures ?? '–' }}</td></tr>
                <tr><th>Name des Ersthelfers</th><td>{{ $entry->first_aider_name ?? '–' }}</td></tr>
                @if($entry->kind !== 'incident')<tr><th>Arztbesuch</th><td>{{ $entry->doctor_visited ? 'ja' : 'nein' }}{{ $entry->reported_at ? ' · Unfallanzeige am '.$entry->reported_at->setTimezone('Europe/Berlin')->format('d.m.Y').($entry->report_note ? ' ('.$entry->report_note.')' : '') : '' }}</td></tr>@endif
                <tr><th>Erfasst</th><td>{{ $entry->recorded_by_name }}, {{ $time($entry->created_at) }}</td></tr>
                @foreach($entry->amendments as $amendment)
                    <tr><th>Nachtrag {{ $amendment->user_name }}, {{ $time($amendment->created_at) }}</th><td>{{ $amendment->text }}</td></tr>
                @endforeach
                @if($entry->isVoided())<tr><th>Storniert</th><td>{{ $time($entry->voided_at) }}: {{ $entry->void_reason }}</td></tr>@endif
            </table>
            <div class="signs">
                @foreach($entry->signatures as $signature)
                    <figure><img src="{{ $signature->dataUrl() }}" alt="Unterschrift {{ $signature->name }}"><figcaption>{{ $signature->roleLabel() }}: {{ $signature->name }}, {{ $time($signature->signed_at) }}</figcaption></figure>
                @endforeach
            </div>
            <p class="hash">Prüfsumme {{ $entry->content_hash }} · {{ $service->verify($entry) ? 'unverändert' : 'PRÜFUNG FEHLGESCHLAGEN' }}</p>
        </div>
    @empty
        <p>Keine Einträge.</p>
    @endforelse
</body>
</html>

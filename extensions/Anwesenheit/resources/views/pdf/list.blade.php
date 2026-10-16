<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111827; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #9ca3af; padding: 4px 6px; text-align: left; }
        th { background: #f3f4f6; }
        .meta td { border: 0; padding: 2px 6px 2px 0; }
        .muted { color: #6b7280; }
    </style>
</head>
<body>
    <p class="muted">{{ $org }}</p>
    <h1>Anwesenheitsliste: {{ $list->title }}</h1>
    <table class="meta">
        <tr><td>Art</td><td>{{ $list->kindLabel() }}</td><td>Leitung</td><td>{{ $list->leader?->name ?? '–' }}</td></tr>
        <tr><td>Beginn</td><td>{{ $list->starts_at?->timezone('Europe/Berlin')->format('d.m.Y H:i') ?? '–' }}</td><td>Ende</td><td>{{ $list->ends_at?->timezone('Europe/Berlin')->format('d.m.Y H:i') ?? '–' }}</td></tr>
        <tr><td>Dauer</td><td>{{ $list->durationLabel() }}</td><td>Teilnehmerzahl</td><td>{{ $list->participant_count }}@if($list->guests) (davon {{ $list->guests }} Gäste)@endif</td></tr>
    </table>
    <table>
        <thead><tr><th style="width:30px">Nr.</th><th>Name</th><th>Funktion</th></tr></thead>
        <tbody>
            @foreach($list->participants as $i => $person)
                <tr><td>{{ $i + 1 }}</td><td>{{ $person->name }}</td><td>{{ $person->id === $list->leader_id ? 'Leitung' : '' }}</td></tr>
            @endforeach
        </tbody>
    </table>
    @if($list->notes)<p><strong>Bemerkungen:</strong> {!! nl2br(e($list->notes)) !!}</p>@endif
    <p class="muted">Erstellt am {{ now('Europe/Berlin')->format('d.m.Y H:i') }} Uhr{{ $list->creator ? ' von '.$list->creator->name : '' }}.</p>
</body>
</html>

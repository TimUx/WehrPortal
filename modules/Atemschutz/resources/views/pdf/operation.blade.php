@php($t = fn ($v) => $v?->setTimezone('Europe/Berlin')->format('H:i') ?? '–')
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Atemschutzüberwachung</title>
    <style>
        @page { margin: 1.4cm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #111827; }
        h1 { font-size: 14pt; margin: 0 0 2pt; }
        h2 { font-size: 11pt; margin: 12pt 0 4pt; }
        .meta { color: #4b5563; margin: 0 0 8pt; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 6pt; }
        th, td { border: 1px solid #9ca3af; padding: 3pt 4pt; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; }
        .warn { color: #b91c1c; }
        .small { font-size: 7.5pt; color: #6b7280; }
    </style>
</head>
<body>
    <h1>Atemschutzüberwachung – {{ $operation->title }}</h1>
    <p class="meta">{{ $org }} · {{ \Modules\Atemschutz\src\Models\Operation::KINDS[$operation->kind] }} am {{ $operation->started_at->setTimezone('Europe/Berlin')->format('d.m.Y') }}, {{ $t($operation->started_at) }}–{{ $t($operation->ended_at) }} Uhr
        @if($operation->report) · Einsatz {{ $operation->report->number }} ({{ $operation->report->keyword }})@endif · Überwachung: {{ $operation->starter?->name ?? '–' }}</p>

    @forelse($operation->teams as $team)
        @php($state = $states[$team->id])
        <h2>{{ $team->name }}{{ $team->task ? ' – '.$team->task : '' }}</h2>
        <table>
            <tr><th>Anschluss</th><th>Ziel erreicht</th><th>Rückzug</th><th>Abgelegt</th><th>Dauer</th><th>Erwartet</th><th>Rückzugsdruck</th></tr>
            <tr><td>{{ $t($team->started_at) }}</td><td>{{ $t($team->target_reached_at) }}{{ $team->target_pressure ? ' ('.$team->target_pressure.' bar)' : '' }}</td><td>{{ $t($team->withdrawn_at) }}</td><td>{{ $t($team->ended_at) }}</td>
                <td>{{ $team->minutes() ?? '–' }} min</td><td>{{ $state['expected'] }} min</td><td>{{ $state['retreat'] }} bar</td></tr>
        </table>
        <table>
            <tr><th>Geräteträger</th><th>Start</th><th>Druckverlauf</th><th>Ende</th><th>Hinweis</th></tr>
            @foreach($team->members as $member)
                <tr>
                    <td>{{ $member->name }}{{ $member->is_leader ? ' (Truppführer)' : '' }}</td>
                    <td>{{ $member->start_pressure }} bar</td>
                    <td>{{ $team->readings->where('team_member_id', $member->id)->whereNotIn('kind', ['start', 'end'])->map(fn ($r) => $t($r->recorded_at).': '.$r->pressure.($r->kind === 'target' ? ' (Ziel)' : ''))->implode(', ') ?: '–' }}</td>
                    <td>{{ $member->end_pressure !== null ? $member->end_pressure.' bar' : '–' }}</td>
                    <td class="{{ $member->override_reason ? 'warn' : '' }}">{{ $member->override_reason ? 'eingesetzt trotz: '.$member->override_reason : '' }}</td>
                </tr>
            @endforeach
        </table>
        @if($team->warnings->isNotEmpty())
            <table>
                <tr><th>Warnung</th><th>um</th><th>quittiert</th></tr>
                @foreach($team->warnings as $w)
                    <tr class="warn"><td>{{ $w->message }}</td><td>{{ $t($w->raised_at) }}</td><td>{{ $w->acknowledged_at ? $t($w->acknowledged_at).' ('.($w->acknowledger?->name ?? '–').')' : '–' }}</td></tr>
                @endforeach
            </table>
        @endif
        <p class="small">Berechnung: Flasche {{ str_replace('.', ',', (string) $team->cylinder_liters) }} l, Verbrauch {{ $team->consumption_lpm }} l/min, Reserve {{ $team->reserve_bar }} bar.</p>
    @empty
        <p>Kein Trupp eingesetzt.</p>
    @endforelse
    <p class="small">Erstellt {{ now('Europe/Berlin')->format('d.m.Y H:i') }} · Dokumentation nach FwDV 7.</p>
</body>
</html>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $plan->title }} – Schichtplan</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: ui-sans-serif, system-ui, sans-serif; color: #111827; margin: 1.5rem; }
        h1 { font-size: 1.4rem; margin: 0; }
        h2 { font-size: 1rem; margin: 1.5rem 0 .5rem; }
        .meta { color: #4b5563; margin: .25rem 0 1rem; }
        table { width: 100%; border-collapse: collapse; font-size: .9rem; }
        th, td { border: 1px solid #9ca3af; padding: .4rem .5rem; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; }
        td.time { white-space: nowrap; font-weight: 600; }
        td.empty { background: #fef2f2; color: #b91c1c; }
        .people { columns: 2; column-gap: 2rem; font-size: .9rem; }
        .people div { break-inside: avoid; margin-bottom: .35rem; }
        .print { margin-bottom: 1rem; padding: .5rem 1rem; border-radius: .5rem; border: 1px solid #d1d5db; background: #fff; font-size: .9rem; cursor: pointer; }
        @media print { .print { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <button type="button" class="print" onclick="window.print()">Drucken</button>
    <h1>{{ $plan->title }}</h1>
    <p class="meta">{{ $plan->timeLabel() }}@if($plan->location) · {{ $plan->location }}@endif @if($showSite && $plan->site) · Standort: {{ $plan->site->name }}@endif</p>

    <table>
        <thead>
            <tr><th>Zeit</th>@foreach($plan->stations as $station)<th>{{ $station->name }}</th>@endforeach</tr>
            @if($plan->stations->whereNotNull('lead_user_id')->isNotEmpty())
                <tr><th>Verantwortlich</th>@foreach($plan->stations as $station)<th style="font-weight: normal">{{ $station->lead?->name ?? '–' }}</th>@endforeach</tr>
            @endif
        </thead>
        <tbody>
            @foreach($plan->slots as $slot)
                <tr>
                    <td class="time">{{ $slot->label() }}</td>
                    @foreach($plan->stations as $station)
                        @php($names = $assignments->where('slot_id', $slot->id)->where('station_id', $station->id)->map(fn ($a) => $a->user->name))
                        <td class="{{ $names->count() < $station->capacity ? 'empty' : '' }}">
                            {!! $names->map(fn ($n) => e($n))->implode('<br>') !!}@if($names->count() < $station->capacity){{ $names->isEmpty() ? '' : ' ' }}({{ $station->capacity - $names->count() }} frei)@endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Wer hat wann was</h2>
    <div class="people">
        @foreach($people as $person)
            @php($shifts = $assignments->where('user_id', $person->id)->sortBy(fn ($a) => $plan->slots->firstWhere('id', $a->slot_id)->starts_at))
            @php($leading = $plan->stations->where('lead_user_id', $person->id)->pluck('name'))
            <div><strong>{{ $person->name }}:</strong>
                @if($leading->isNotEmpty())Verantwortlich für {{ $leading->implode(', ') }}{{ $shifts->isNotEmpty() ? ' · ' : '' }}@endif
                {{ $shifts->map(fn ($a) => $plan->slots->firstWhere('id', $a->slot_id)->label().' '.$plan->stations->firstWhere('id', $a->station_id)->name)->implode(' · ') ?: ($leading->isEmpty() ? 'keine Schicht' : '') }}
            </div>
        @endforeach
    </div>
</body>
</html>

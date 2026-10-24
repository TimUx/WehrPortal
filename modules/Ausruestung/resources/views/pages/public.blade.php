<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $equipment->name }} – {{ $org }}</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; color: #111827; margin: 0; background: #f6f7f9; }
        header { background: #1B2645; color: #fff; padding: .9rem 1rem; font-weight: 600; }
        main { max-width: 32rem; margin: 0 auto; padding: 1rem; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 1rem; padding: 1rem; }
        h1 { font-size: 1.35rem; margin: 0; }
        .muted { color: #6b7280; font-size: .9rem; }
        .status { display: inline-block; margin-top: .75rem; padding: .35rem .75rem; border-radius: 9999px; font-weight: 700; }
        .ok { background: #dcfce7; color: #166534; }
        .bad { background: #fee2e2; color: #991b1b; }
        .warn { margin-top: .5rem; color: #991b1b; font-weight: 600; }
        dl { margin: 1rem 0 0; display: grid; gap: .6rem; }
        dt { font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; }
        dd { margin: 0; font-weight: 600; }
        a { color: #1B2645; }
    </style>
</head>
<body>
    <header>{{ $org }} · Geräteinfo</header>
    <main>
        <div class="card">
            <h1>{{ $equipment->name }}</h1>
            <p class="muted">{{ $equipment->code }}@if($equipment->category) · {{ $equipment->category->name }}@endif</p>
            <span class="status {{ $equipment->isUsable() ? 'ok' : 'bad' }}">{{ $equipment->isUsable() ? 'Einsatzbereit' : ($equipment->status->value === 'available' ? 'Nicht einsatzbereit' : $equipment->status->label()) }}</span>
            @foreach($equipment->warnings() as $warning)<p class="warn">{{ $warning }}</p>@endforeach
            <dl>
                <div><dt>Nächste Prüfung</dt><dd>{{ $equipment->next_inspection_at?->format('d.m.Y') ?? 'keine Prüffrist' }}</dd></div>
                <div><dt>Gehört nach</dt><dd>{{ $equipment->placeLabel() }}</dd></div>
            </dl>
        </div>
        <p class="muted" style="margin-top:1rem">Mehr Details, Schaden melden oder ausleihen: <a href="{{ route('ausruestung.show', $equipment) }}">in WehrPortal anmelden</a>.</p>
    </main>
</body>
</html>

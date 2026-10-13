@inject('org', \App\Services\OrganizationSettings::class)
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Jahresbericht {{ $year }} – {{ $org->shortName() }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: ui-sans-serif, system-ui, sans-serif; color: #111827; margin: 1.5rem auto; max-width: 52rem; padding: 0 1rem; font-size: .9rem; }
        h1 { font-size: 1.5rem; margin: 0; }
        h2 { font-size: 1.05rem; margin: 1.5rem 0 .5rem; border-bottom: 2px solid #ED2F12; padding-bottom: .2rem; }
        h3 { font-size: .9rem; margin: .9rem 0 .3rem; }
        .meta { color: #4b5563; margin: .25rem 0 1rem; }
        .ar-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: .5rem; }
        .ar-tile { border: 1px solid #e5e7eb; border-radius: .5rem; padding: .5rem .65rem; }
        .ar-tile span { display: block; font-size: .72rem; color: #6b7280; }
        .ar-tile strong { font-size: 1.15rem; }
        table { width: 100%; border-collapse: collapse; margin-top: .25rem; }
        th, td { border-bottom: 1px solid #e5e7eb; padding: .3rem .4rem; text-align: left; }
        th { color: #6b7280; font-weight: 600; font-size: .78rem; }
        td.n, th.n { text-align: right; }
        .note { color: #6b7280; font-size: .78rem; }
        section { break-inside: avoid; }
        .bar { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: 1rem; }
        .bar button, .bar a { padding: .45rem .9rem; border-radius: .5rem; border: 1px solid #d1d5db; background: #fff; color: #111827; text-decoration: none; font-size: .85rem; cursor: pointer; }
        .bar button { background: #ED2F12; border-color: #ED2F12; color: #fff; font-weight: 600; }
        @media print { .bar { display: none; } body { margin: 0 auto; } }
    </style>
</head>
<body>
    <div class="bar">
        <button onclick="window.print()">Drucken / als PDF speichern</button>
        <a href="{{ route('einsatzbericht.annual', ['jahr' => $year - 1]) }}">‹ {{ $year - 1 }}</a>
        <a href="{{ route('einsatzbericht.annual', ['jahr' => $year + 1]) }}">{{ $year + 1 }} ›</a>
        <a href="{{ route('einsatzbericht.statistics', ['jahr' => $year]) }}">Jahresstatistik</a>
    </div>
    <h1>Jahresbericht {{ $year }}</h1>
    <p class="meta">{{ $org->name() }} · Stand {{ now('Europe/Berlin')->format('d.m.Y') }}</p>
    @foreach($sections as $section)
        <section>
            <h2>{{ $section['title'] }}</h2>
            {!! $section['html'] !!}
        </section>
    @endforeach
</body>
</html>

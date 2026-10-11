<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ $template->name }}</title>
    <style>
        @page { margin: 1.5cm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #111827; }
        h1 { font-size: 15pt; margin: 0 0 2pt; }
        .meta { color: #4b5563; margin: 0 0 10pt; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12pt; }
        th, td { border: 1px solid #9ca3af; padding: 6pt 5pt; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; }
        .box { width: 11%; text-align: center; }
        .line { height: 26pt; }
    </style>
</head>
<body>
    <h1>{{ $template->name }}</h1>
    <p class="meta">{{ $org }} · {{ $template->intervalLabel() }}@if($template->required_qualification) · Qualifikation: {{ $template->required_qualification }}@endif</p>
    @if($template->description)<p>{!! nl2br(e($template->description)) !!}</p>@endif

    <table>
        <tr><td style="width: 30%">Gerät (Code, Name)</td><td class="line"></td></tr>
        <tr><td>Datum</td><td class="line"></td></tr>
    </table>

    <table>
        <thead><tr><th>Prüfpunkt</th><th class="box">i. O.</th><th class="box">Mangel</th><th class="box">entfällt</th><th style="width: 30%">Bemerkung</th></tr></thead>
        <tbody>
            @foreach($template->checklist as $item)
                <tr><td>{{ $item }}</td><td class="box">☐</td><td class="box">☐</td><td class="box">☐</td><td></td></tr>
            @endforeach
        </tbody>
    </table>

    <table>
        <tr><td style="width: 30%">Ergebnis</td><td>☐ bestanden &nbsp;&nbsp; ☐ nicht bestanden</td></tr>
        <tr><td>Zeitaufwand (Minuten)</td><td class="line"></td></tr>
        <tr><td>Prüfer, Prüfer-ID</td><td class="line"></td></tr>
        <tr><td>Unterschrift</td><td class="line"></td></tr>
    </table>
</body>
</html>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $preview['title'] }}</title>
    @include('partials.og-tags')
</head>
<body>
    <h1>{{ $preview['title'] }}</h1>
    <p>{{ $preview['description'] }}</p>
    <p><a href="{{ $preview['url'] }}">Öffnen (Anmeldung erforderlich)</a></p>
</body>
</html>

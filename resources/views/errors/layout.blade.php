<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') – {{ config('app.name') }}</title>
    {{-- Inline styles on purpose: error pages must render even if the asset build is missing --}}
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 1.5rem; background: #F7F7F7; color: #222222;
            font-family: ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji';
        }
        main { max-width: 32rem; text-align: center; }
        img { height: 9rem; width: auto; margin-bottom: 1.5rem; }
        .code { font-size: .875rem; font-weight: 600; letter-spacing: .1em; color: #A0A0A0; }
        h1 { margin: .5rem 0 1rem; font-size: 1.75rem; line-height: 1.25; color: #9B0103; }
        p { margin: .25rem 0; font-size: 1.05rem; line-height: 1.5; color: #4A4A4A; }
        a {
            display: inline-block; margin-top: 2rem; padding: .75rem 1.5rem; border-radius: .75rem;
            background: #ED2F12; color: #fff; font-weight: 600; text-decoration: none;
        }
        a:hover { background: #9B0103; }
    </style>
</head>
<body>
    <main>
        <img src="{{ asset('images/wehrportal-logo.svg') }}" alt="{{ config('app.name') }}">
        <div class="code">@yield('code')</div>
        <h1>@yield('heading')</h1>
        @yield('message')
        <a href="{{ url('/') }}">Zur Startseite</a>
    </main>
</body>
</html>

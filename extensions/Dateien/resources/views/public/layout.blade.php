<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Freigabe – {{ config('app.name') }}</title>
    <style nonce="{{ $nonce }}">
        body { margin: 0; font-family: system-ui, sans-serif; background: #f3f4f6; color: #111827; }
        header { background: #1e3a8a; color: #fff; padding: .75rem 1rem; font-weight: 600; }
        main { max-width: 52rem; margin: 1.5rem auto; padding: 0 1rem; }
        .card { background: #fff; border-radius: .5rem; box-shadow: 0 1px 2px rgba(0,0,0,.08); padding: 1rem 1.25rem; }
        .muted { color: #6b7280; font-size: .875rem; }
        .crumbs a, table a { color: #1e3a8a; text-decoration: none; }
        .crumbs a:hover, table a:hover { text-decoration: underline; }
        table { width: 100%; border-collapse: collapse; margin-top: .75rem; }
        td, th { text-align: left; padding: .5rem .25rem; border-bottom: 1px solid #e5e7eb; font-size: .9375rem; }
        th { color: #6b7280; font-weight: 500; font-size: .8125rem; }
        .num { text-align: right; white-space: nowrap; }
        .btn { display: inline-block; background: #1e3a8a; color: #fff; border: 0; border-radius: .375rem; padding: .5rem 1rem; font-size: .9375rem; text-decoration: none; cursor: pointer; }
        input[type=password] { width: 100%; box-sizing: border-box; padding: .5rem; border: 1px solid #d1d5db; border-radius: .375rem; margin: .5rem 0 .75rem; font-size: 1rem; }
        h1 { font-size: 1.25rem; margin: 0 0 .5rem; }
        .error { color: #b91c1c; margin: .5rem 0; }
    </style>
</head>
<body>
<header>{{ config('app.name') }} · Freigabe</header>
<main>@yield('content')</main>
</body>
</html>

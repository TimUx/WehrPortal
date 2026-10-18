<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ $node->name }} – Dateien</title>
    <style>
        html, body { margin: 0; height: 100%; font-family: system-ui, sans-serif; }
        body { display: flex; flex-direction: column; }
        header { display: flex; gap: 1rem; align-items: center; padding: .5rem 1rem; background: #1e3a5f; color: #fff; font-size: .9rem; }
        header a { color: #fff; }
        header span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        main { flex: 1; min-height: 0; }
        main > div, main iframe { width: 100%; height: 100%; border: 0; display: block; }
    </style>
</head>
<body>
<header>
    <a href="{{ $back }}">← Zurück zu Dateien</a>
    <span>{{ $node->name }} · nur lesen</span>
</header>
<main>
    @if ($editor['mode'] === 'onlyoffice')
        <div id="dateien-office"></div>
        <script nonce="{{ $nonce }}" src="{{ $editor['script'] }}"></script>
        <script nonce="{{ $nonce }}">
            new DocsAPI.DocEditor('dateien-office', Object.assign(@json($editor['config']), { width: '100%', height: '100%' }));
        </script>
    @else
        <form id="dateien-office-form" method="post" action="{{ $editor['action'] }}" target="dateien-office-frame">
            <input type="hidden" name="access_token" value="{{ $editor['token'] }}">
            <input type="hidden" name="access_token_ttl" value="{{ $editor['ttl'] }}">
        </form>
        <iframe name="dateien-office-frame" referrerpolicy="no-referrer" title="{{ $node->name }}" allow="clipboard-read; clipboard-write; fullscreen"></iframe>
        <script nonce="{{ $nonce }}">document.getElementById('dateien-office-form').submit();</script>
    @endif
</main>
</body>
</html>

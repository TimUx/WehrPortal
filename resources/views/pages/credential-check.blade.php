@php($org = app(\App\Services\OrganizationSettings::class))
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Nachweis prüfen – {{ $org->shortName() }}</title>
    <style>
        body { margin: 0; background: #f3f4f6; font-family: ui-sans-serif, system-ui, sans-serif; color: #111827; }
        main { max-width: 28rem; margin: 0 auto; padding: 1.25rem 1rem; }
        .head { display: flex; align-items: center; gap: .9rem; background: #1B2645; color: #fff; border-radius: 1rem 1rem 0 0; padding: 1rem; }
        .head img { width: 4rem; height: 4rem; border-radius: .75rem; object-fit: cover; background: #fff; }
        .head strong { display: block; font-size: 1.15rem; }
        .body { background: #fff; border-radius: 0 0 1rem 1rem; padding: 1rem; }
        .ok { color: #065f46; background: #ecfdf5; border-radius: .6rem; padding: .5rem .75rem; font-weight: 600; }
        .bad { color: #991b1b; background: #fef2f2; border-radius: .6rem; padding: .5rem .75rem; font-weight: 600; }
        h2 { font-size: .95rem; margin: 1rem 0 .4rem; }
        dl { display: grid; grid-template-columns: 9rem 1fr; gap: .25rem .75rem; margin: 0; font-size: .9rem; }
        dt { color: #6b7280; }
        .muted { color: #6b7280; font-size: .8rem; text-align: center; margin-top: .75rem; }
    </style>
</head>
<body>
<main>
    <div class="head">
        @if($member->avatar)<img src="data:{{ $member->avatar->mime }};base64,{{ $member->avatar->data }}" alt="">@endif
        <div><strong>{{ $member->name }}</strong>{{ $org->name() }}</div>
    </div>
    <div class="body">
        @if(! $member->is_active)
            <p class="bad">Kein aktives Mitglied dieser Wehr.</p>
        @elseif($items === [])
            <p class="bad">Für diese Person sind keine Nachweise hinterlegt.</p>
        @else
            <p class="ok">Aktives Mitglied</p>
            @foreach($items as $item)
                <h2>{{ $item['title'] }}</h2>
                {!! $item['html'] !!}
            @endforeach
        @endif
        <p class="muted">Stand {{ now('Europe/Berlin')->format('d.m.Y, H:i') }} Uhr · aus openwehr</p>
    </div>
</main>
</body>
</html>

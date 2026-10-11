<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Termine – {{ $org }}</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; color: #111827; margin: 0; padding: 1rem; background: transparent; }
        .event { padding: .75rem 0; border-bottom: 1px solid #e5e7eb; }
        .event:last-child { border-bottom: 0; }
        .when { font-size: .85rem; color: #b91c1c; font-weight: 600; }
        .title { font-size: 1rem; font-weight: 600; margin: .15rem 0; }
        .where, .text { font-size: .875rem; color: #4b5563; }
        .text { white-space: pre-line; margin-top: .25rem; }
        .empty { color: #6b7280; font-size: .9rem; }
    </style>
</head>
<body>
    @forelse($events as $event)
        <div class="event">
            <div class="when">{{ $event->timeLabel() }}</div>
            <div class="title">{{ $event->title }}</div>
            @if($event->location)<div class="where">{{ $event->location }}</div>@endif
            @if($event->description)<div class="text">{{ $event->description }}</div>@endif
        </div>
    @empty
        <p class="empty">Zurzeit stehen keine öffentlichen Termine an.</p>
    @endforelse
</body>
</html>

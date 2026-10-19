<div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Lehrgänge</p>
    @if($next)
        <p class="mt-1 text-sm text-gray-900">Dein nächster Lehrgang: <span class="font-semibold">{{ $next->course->name }}</span> ab {{ $next->starts_on->format('d.m.Y') }}</p>
    @endif
    @foreach($open as $r)
        <p class="mt-1 text-sm text-gray-700">„{{ $r->course->name }}“ – {{ $r->isWaitlisted() ? 'in fox112 angemeldet, auf der Warteliste' : 'Interesse gemeldet, wartet auf die Anmeldung' }}</p>
    @endforeach
    @if(! $next && $open->isEmpty())
        <p class="mt-1 text-sm text-gray-700">Gerade kein Lehrgang angemeldet.</p>
    @endif
    <p class="mt-1 text-xs text-gray-500">{{ $completed }} {{ $completed === 1 ? 'Lehrgang' : 'Lehrgänge' }} absolviert</p>
    @if($canOpen)<a href="{{ route('lehrgaenge.index') }}" class="text-sm text-fw-navy underline">Meine Lehrgänge &amp; Angebote</a>@endif
    @foreach($refresh as $r)
        <p class="mt-1 text-sm {{ $r->refreshState() === 'expired' ? 'font-semibold text-red-700' : 'text-amber-800' }}">Auffrischung „{{ $r->course->name }}“ {{ $r->refreshState() === 'expired' ? 'überfällig seit' : 'fällig bis' }} {{ $r->validUntil()->format('d.m.Y') }}</p>
    @endforeach
    @if(array_sum($todo) > 0)
        <ul class="mt-2 space-y-0.5 text-sm text-gray-700">
            @if($todo['cancelled'] > 0)<li class="font-semibold text-red-700">{{ $todo['cancelled'] }} Absage{{ $todo['cancelled'] === 1 ? '' : 'n' }} – in fox112 abmelden und als erledigt markieren</li>@endif
            @if(($todo['suggested'] ?? 0) > 0)<li><a href="{{ route('lehrgaenge.courses') }}" class="underline">{{ $todo['suggested'] }} Vorschl{{ $todo['suggested'] === 1 ? 'ag' : 'äge' }} aus fox112</a> – anbieten oder nicht?</li>@endif
            @if(($todo['gone'] ?? 0) > 0)<li><a href="{{ route('lehrgaenge.courses') }}" class="underline">{{ $todo['gone'] }} angebotene{{ $todo['gone'] === 1 ? 'r Lehrgang' : ' Lehrgänge' }} nicht mehr in fox112</a></li>@endif
            @if($todo['requested'] > 0)<li>{{ $todo['requested'] }} Kamerad{{ $todo['requested'] === 1 ? '' : 'en' }} mit Interesse an einem Lehrgang – in fox112 anmelden</li>@endif
        </ul>
        <a href="{{ route('lehrgaenge.registrations') }}" class="text-sm text-fw-navy underline">Lehrgangsanmeldungen</a>
    @endif
</div>

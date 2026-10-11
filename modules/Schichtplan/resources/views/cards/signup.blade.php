<a href="{{ $open->count() === 1 ? route('schichtplan.signup', $open->first()) : route('schichtplan.index') }}" class="block bg-white rounded-2xl border border-gray-100 shadow-sm p-5 hover:border-fw-navy">
    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Schichtplan</p>
    @foreach($open->take(3) as $plan)
        <p class="mt-1 text-sm font-semibold text-gray-900">Helfer gesucht: {{ $plan->title }}</p>
        <p class="text-xs text-gray-500">{{ $plan->timeLabel() }} · eintragen bis {{ $plan->signupDeadline()->copy()->setTimezone('Europe/Berlin')->format('d.m. H:i') }}</p>
    @endforeach
    @if($requests > 0)<p class="mt-1 text-sm font-semibold text-amber-800">{{ $requests }} Schicht-Anfrage{{ $requests === 1 ? '' : 'n' }} zu bestätigen</p>@endif
</a>

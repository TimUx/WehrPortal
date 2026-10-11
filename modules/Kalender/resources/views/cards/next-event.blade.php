<a href="{{ route('kalender.show', $event) }}" class="block bg-white rounded-2xl border border-gray-100 shadow-sm p-5 hover:border-fw-navy hover:shadow-md transition-all">
    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Nächster Termin</p>
    <p class="mt-1 font-semibold text-gray-900">
        <span class="mr-1 inline-block rounded border px-1.5 py-0.5 text-xs {{ $event->colorClasses() }}">{{ $event->typeLabel() }}</span>
        {{ $event->title }}
    </p>
    <p class="mt-1 text-sm text-gray-600">{{ $event->timeLabel() }}</p>
    @if($event->location)<p class="text-sm text-gray-500">{{ $event->location }}</p>@endif
    @if($event->rsvp)
        <p class="mt-2 text-sm {{ $response?->status ? 'text-gray-700' : 'font-semibold text-fw-red' }}">
            {{ $response?->status ? 'Deine Rückmeldung: '.$response->label() : ($event->acceptsResponses() ? 'Bitte Rückmeldung geben' : 'Rückmeldung geschlossen') }}
        </p>
    @endif
</a>

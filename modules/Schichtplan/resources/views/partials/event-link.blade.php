{{-- The Kalender event of the plan, while the Kalender is on and the Kamerad may use it --}}
@if($plan->event_id && \Modules\Schichtplan\src\Services\CalendarLink::available() && auth()->user()->canUseModule('kalender'))
    <a href="{{ route('kalender.show', $plan->event_id) }}" class="text-xs text-fw-navy underline">Im Kalender ansehen</a>
@endif

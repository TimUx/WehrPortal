{{-- On a Kalender event: its Schichtpläne with "Helfer eintragen", and "Schichtplan anlegen" for planers --}}
@php
    $user = auth()->user();
    $linked = app(\Modules\Schichtplan\src\Services\CalendarLink::class)->plansFor($event, $user);
    $canCreate = app(\Modules\Schichtplan\src\Services\PlanLocationAccess::class)->canCreate($user)
        && ! $event->isExternal() && ! $event->isCancelled();
@endphp
@if($linked->isNotEmpty() || $canCreate)
    <section class="mx-auto mt-4 max-w-3xl space-y-2 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-gray-900">Schichtplan</h2>
            @if($canCreate)
                <a href="{{ route('schichtplan.create', ['termin' => $event->id]) }}" class="text-sm text-fw-navy underline">Schichtplan anlegen</a>
            @endif
        </div>
        @forelse($linked as $plan)
            @php($mine = $plan->assignments()->where('user_id', $user->id)->count())
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 pt-2 text-sm">
                <div>
                    <p class="font-medium text-gray-900">{{ $plan->title }} @unless($plan->isPublished())<span class="text-xs font-normal text-gray-500">(Entwurf)</span>@endunless</p>
                    <p class="text-xs text-gray-500">{{ $plan->timeLabel() }}@if($mine) · du hast {{ $mine }} Schicht{{ $mine === 1 ? '' : 'en' }}@endif</p>
                </div>
                <div class="flex gap-2">
                    @if($plan->signupOpen() && \Modules\Schichtplan\src\Services\SignupService::mayJoin($user, $plan))
                        <a href="{{ route('schichtplan.signup', $plan) }}" class="inline-flex items-center rounded-lg bg-green-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-green-700">Helfer eintragen</a>
                    @endif
                    <a href="{{ $plan->isPublished() ? route('schichtplan.show', $plan) : route('schichtplan.edit', $plan) }}" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Plan öffnen</a>
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500">Noch kein Schichtplan. Damit verteilst du Helfer auf Stationen und Zeitblöcke oder lässt sie sich selbst eintragen.</p>
        @endforelse
    </section>
@endif

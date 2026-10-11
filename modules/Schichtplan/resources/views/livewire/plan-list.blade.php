<div class="space-y-8">
    <section>
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <h1 class="text-xl font-bold text-gray-900">Meine Schichten</h1>
            @if($manager)
                <a href="{{ route('schichtplan.create') }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-fw-red text-white text-sm font-semibold hover:bg-fw-red-dark">Neuer Plan</a>
            @endif
        </div>

        @if($mine->isEmpty())
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 text-sm text-gray-600">Du bist in keinem anstehenden Schichtplan eingeteilt.</div>
        @else
            <div class="space-y-3">
                @foreach($mine as $entry)
                    <a href="{{ route('schichtplan.show', $entry['plan']) }}" class="block bg-white rounded-2xl border border-gray-100 shadow-sm p-4 hover:border-fw-navy" wire:key="mine-{{ $entry['plan']->id }}">
                        <p class="font-semibold text-gray-900">{{ $entry['plan']->title }}</p>
                        <p class="text-sm text-gray-500">{{ $entry['plan']->timeLabel() }}@if($entry['plan']->location) · {{ $entry['plan']->location }}@endif</p>
                        @if($entry['leads']->isNotEmpty())
                            <p class="mt-2 text-sm font-semibold text-fw-navy">Stationsverantwortung: {{ $entry['leads']->implode(', ') }}</p>
                        @endif
                        @if($entry['shifts']->isEmpty())
                            <p class="mt-2 text-sm text-gray-600">Keine Schicht eingeteilt.</p>
                        @else
                            <ul class="mt-2 flex flex-wrap gap-2">
                                @foreach($entry['shifts'] as $shift)
                                    <li class="px-2.5 py-1 rounded-lg bg-red-50 text-sm text-red-900"><span class="font-semibold">{{ $shift->slot->label() }}</span> {{ $shift->station->name }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    @if($manager)
        <section>
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <h2 class="text-lg font-bold text-gray-900">Alle Pläne</h2>
                <label class="inline-flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" wire:model.live="showPast" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> Vergangene zeigen
                </label>
            </div>
            @if($all->isEmpty())
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 text-sm text-gray-600">Noch keine Pläne. Lege mit „Neuer Plan“ den ersten an.</div>
            @else
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100">
                    @foreach($all as $plan)
                        <a href="{{ route('schichtplan.edit', $plan) }}" class="block p-4 hover:bg-gray-50" wire:key="all-{{ $plan->id }}">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-semibold text-gray-900 truncate">{{ $plan->title }}</p>
                                    <p class="text-sm text-gray-500">{{ $plan->timeLabel() }} · {{ $plan->stations_count }} Stationen · {{ $plan->participants_count }} Teilnehmer</p>
                                </div>
                                <span class="shrink-0 px-2 py-0.5 rounded-full text-xs font-semibold {{ $plan->isPublished() ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700' }}">{{ $plan->isPublished() ? 'Veröffentlicht' : 'Entwurf' }}</span>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>
    @endif
</div>

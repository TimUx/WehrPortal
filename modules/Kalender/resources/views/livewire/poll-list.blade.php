<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <a href="{{ route('kalender.index') }}" class="inline-flex items-center gap-1 rounded-lg py-1.5 pr-2 text-sm font-medium text-fw-navy hover:text-fw-red">← Kalender</a>
            <h1 class="text-xl font-bold text-gray-900">Terminabfragen</h1>
            <p class="text-sm text-gray-500">Wann passt es den meisten? Bei jedem Vorschlag ankreuzen – der Terminverwalter legt danach den Termin fest.</p>
        </div>
        @if($manager)<a href="{{ route('kalender.polls.create') }}" class="rounded-lg bg-fw-red px-3 py-1.5 text-sm font-semibold text-white hover:bg-fw-red-dark">Neue Terminabfrage</a>@endif
    </div>
    @if($manager)<label class="inline-flex items-center gap-2 text-sm text-gray-600"><input type="checkbox" wire:model.live="showClosed" class="rounded"> Geschlossene zeigen</label>@endif
    <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
        @forelse($polls as $poll)
            <a href="{{ route('kalender.polls.show', $poll) }}" class="flex items-center justify-between gap-3 p-4 hover:bg-gray-50" wire:key="p-{{ $poll->id }}">
                <span class="min-w-0">
                    <span class="block font-semibold text-gray-900">{{ $poll->title }}</span>
                    <span class="block text-sm text-gray-500">{{ $poll->typeLabel() }} · {{ $poll->options_count }} Vorschläge{{ $poll->deadline ? ' · bis '.$poll->deadline->copy()->setTimezone('Europe/Berlin')->format('d.m. H:i') : '' }}</span>
                </span>
                <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $poll->isDecided() ? 'bg-green-100 text-green-800' : (! $poll->isOpen() ? 'bg-gray-100 text-gray-700' : ($answered->has($poll->id) ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-900')) }}">
                    {{ $poll->isDecided() ? 'Termin festgelegt' : (! $poll->isOpen() ? 'geschlossen' : ($answered->has($poll->id) ? 'beantwortet' : 'bitte ankreuzen')) }}
                </span>
            </a>
        @empty
            <p class="p-4 text-sm text-gray-500">Keine offenen Terminabfragen.</p>
        @endforelse
    </div>
</div>

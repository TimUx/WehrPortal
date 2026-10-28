<div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Mängel</h1>
            <p class="text-sm text-gray-500">{{ $openCount }} {{ $openCount === 1 ? 'offener Mangel' : 'offene Mängel' }}</p>
        </div>
        <a href="{{ route('maengelmanagement.report') }}"
           class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-fw-red text-white text-sm font-semibold hover:bg-fw-red-dark">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            Mangel melden
        </a>
    </div>

    <div class="flex flex-wrap items-center gap-2 mb-2 text-sm">
        @foreach(['alle' => 'Alle', 'meine' => 'Meine Meldungen', 'mir' => 'Mir zugewiesen', 'rueckfrage' => 'Rückfrage offen'] as $value => $label)
            <button type="button" wire:click="$set('view', '{{ $value }}')"
                    class="px-3 py-1 rounded-lg {{ $view === $value ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">{{ $label }}@if(($counts[$value] ?? 0) > 0) <span class="ml-1 text-xs opacity-75">{{ $counts[$value] }}</span>@endif</button>
        @endforeach
    </div>
    <div class="flex flex-wrap items-center gap-2 mb-4">
        @foreach(['active' => 'Aktiv', 'all' => 'Alle', 'resolved' => 'Erledigt', 'rejected' => 'Abgelehnt'] as $value => $label)
            <button type="button" wire:click="$set('filter', '{{ $value }}')"
                    class="px-3 py-1.5 rounded-full text-sm {{ $filter === $value ? 'bg-fw-navy text-white' : 'bg-white border border-gray-200 text-gray-700 hover:border-fw-navy' }}">
                {{ $label }}
            </button>
        @endforeach
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Suchen …"
               class="ml-auto w-full sm:w-56 rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-fw-red focus:ring-fw-red">
    </div>

    @if($defects->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 text-sm text-gray-600">
            Keine Mängel in dieser Ansicht. Gut so!
        </div>
    @else
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100">
            @foreach($defects as $defect)
                <a href="{{ route('maengelmanagement.show', $defect) }}" wire:key="d-{{ $defect->id }}" class="block p-4 hover:bg-gray-50">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-semibold text-gray-900 truncate">{{ $defect->title }}</p>
                            <p class="text-sm text-gray-500 mt-0.5 truncate">
                                {{ $defect->subject() }}
                                @if(config('locations.enabled') && $defect->site) · {{ $defect->site->name }}@endif
                                · {{ $defect->reporter?->name ?? 'Unbekannt' }}
                                · {{ $defect->created_at->setTimezone('Europe/Berlin')->format('d.m.Y') }}
                                @if($defect->photos_count > 0)· {{ $defect->photos_count }} Foto{{ $defect->photos_count > 1 ? 's' : '' }}@endif
                                @if($defect->assignee)· zuständig: {{ $defect->assignee->name }}@endif
                            </p>
                        </div>
                        <div class="flex flex-col items-end gap-1 shrink-0">
                            <span class="px-2 py-0.5 rounded-full text-xs font-semibold {{ $defect->status->badgeClasses() }}">{{ $defect->status->label() }}</span>
                            <span class="px-2 py-0.5 rounded-full text-xs font-semibold {{ $defect->urgency->badgeClasses() }}">{{ $defect->urgency->label() }}</span>
                            @if($defect->question_open_at)<span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-900">Rückfrage</span>@endif
                        </div>
                    </div>
                </a>
            @endforeach
        </div>

        <div class="mt-4">{{ $defects->links() }}</div>
    @endif
</div>

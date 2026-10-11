<div class="space-y-6">
    <section>
        <h2 class="text-sm font-semibold text-gray-900 mb-2">Offen ({{ $open->count() }})</h2>
        <div class="space-y-2">
            @forelse($open as $decision)
            <a href="{{ route('fahrzeugbuchung.decisions.show', $decision) }}" class="block rounded-xl border border-fw-red bg-white px-4 py-3 hover:bg-red-50 transition-colors">
                @include('fahrzeugbuchung::livewire.decisions.partials.row', ['decision' => $decision])
            </a>
            @empty
            <p class="text-sm text-gray-400 py-4">Keine offenen Entscheidungen.</p>
            @endforelse
        </div>
    </section>

    @if($closed->isNotEmpty())
    <section>
        <h2 class="text-sm font-semibold text-gray-900 mb-2">Erledigt</h2>
        <div class="space-y-2">
            @foreach($closed as $decision)
            <a href="{{ route('fahrzeugbuchung.decisions.show', $decision) }}" class="block rounded-xl border border-gray-200 bg-white px-4 py-3 hover:bg-gray-50 transition-colors">
                @include('fahrzeugbuchung::livewire.decisions.partials.row', ['decision' => $decision])
                <p class="mt-1 text-xs text-gray-500">
                    {{ $decision->statusLabel() }}
                    @if($decision->decider) · {{ $decision->decider->name }}@endif
                    @if($decision->decided_at) · {{ $decision->decided_at->setTimezone('Europe/Berlin')->isoFormat('D. MMM YYYY, HH:mm') }}@endif
                </p>
            </a>
            @endforeach
        </div>
    </section>
    @endif
</div>

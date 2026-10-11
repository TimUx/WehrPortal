<x-layouts.app title="Entscheidung">
    <div class="max-w-2xl mx-auto">
        <a href="{{ route('fahrzeugbuchung.decisions.index') }}" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700 mb-3">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            Alle Entscheidungen
        </a>
        <livewire:decisions.decision-detail :decision="$decision" />
    </div>
</x-layouts.app>

<div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Wartungsvorlagen</h1>
            <p class="text-sm text-gray-500">Was geprüft wird, wie oft und wie früh es angezeigt wird – für alle Geräte einer Kategorie oder einzelne Geräte. Die Lebensdauer und das Prüfintervall der Kategorien stehen in der Ausrüstungsverwaltung.</p>
        </div>
        <a href="{{ route('wartung.template.create') }}" class="rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white hover:bg-fw-red-dark">Neue Vorlage</a>
    </div>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif

    <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
        @forelse($templates as $t)
            <div class="grid grid-cols-[1fr_auto] items-center gap-x-3 gap-y-1 p-3 text-sm" wire:key="t-{{ $t->id }}">
                <a href="{{ route('wartung.template', $t) }}" class="min-w-0">
                    <span class="block font-medium {{ $t->is_active ? 'text-gray-900' : 'text-gray-400' }}">{{ $t->name }}{{ $t->is_active ? '' : ' (aus)' }}</span>
                    <span class="block text-xs text-gray-500">
                        {{ $t->intervalLabel() }}{{ $t->interval_months ? ', Voranzeige '.$t->lead_days.' Tage' : '' }} ·
                        {{ $t->category?->name ?? 'ohne Kategorie' }}{{ $t->equipment_count ? ' + '.$t->equipment_count.' einzeln' : '' }} · {{ $t->devices_count }} Geräte ·
                        {{ count($t->checklist) }} Prüfpunkte · {{ \Modules\Wartung\src\Models\Template::PERFORMERS[$t->performer] }}{{ $t->provider ? ' ('.$t->provider->name.')' : '' }}
                        @if($open[$t->id] ?? 0) · <strong>{{ $open[$t->id] }} offen</strong>@endif
                    </span>
                </a>
                <a href="{{ route('wartung.template.pdf', $t) }}" target="_blank" class="text-xs text-fw-navy underline">Checkliste PDF</a>
            </div>
        @empty
            <p class="p-4 text-sm text-gray-500">Noch keine Vorlagen. Beispiel: „Jahresprüfung Feuerwehrleine“ für die Kategorie Feuerwehrleine, jährlich, 30 Tage Voranzeige.</p>
        @endforelse
    </div>

    <section class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">Kostenrechnung</p>
        <p class="text-sm text-gray-600">Der Stundensatz rechnet den Zeitaufwand jedes Protokolls in Kosten um (z. B. für Haushalt oder Versicherung). Er wird beim Speichern ins Protokoll übernommen.</p>
        <div class="flex flex-wrap items-center gap-2">
            <label class="text-sm text-gray-700">Stundensatz in €<input type="text" inputmode="decimal" wire:model="hourlyRate" class="ml-2 w-28 rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></label>
            <button type="button" wire:click="saveRate" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Speichern</button>
        </div>
        @error('hourly_rate')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
    </section>
</div>

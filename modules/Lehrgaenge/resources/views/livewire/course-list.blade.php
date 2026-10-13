<div class="space-y-6">
    <div>
        <h1 class="text-xl font-bold text-gray-900">Lehrgangsliste</h1>
        <p class="text-sm text-gray-500">Kameraden können Interesse an jedem Lehrgang melden, den du hier <strong>anbietest</strong>. fox112 macht nur Vorschläge: Neue Lehrgänge (und gelöschte, die fox112 weiter führt) erscheinen unten zum Anbieten oder Nicht-Anbieten. Verschwindet ein angebotener Lehrgang aus fox112, bleibt er – du entscheidest, ob du ihn weiter anbietest. Fehlt ein Lehrgang ganz, hier von Hand ergänzen.</p>
    </div>

    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif

    <form wire:submit="saveFoxUrl" class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">fox112 des Kreises</p>
        <p class="text-sm text-gray-500">Die Seite „Restplätze Lehrgänge“ eures Kreises im Lehrgangsportal fox112. Es reicht die Adresse des Kreises, z. B. <code>https://kreis.fox112.de</code>. Von dort werden täglich die Lehrgänge ohne festen Termin eingelesen; leer lassen, wenn ihr die Liste nur von Hand pflegt.</p>
        <div class="flex flex-wrap items-start gap-2">
            <input type="text" wire:model="foxUrl" maxlength="255" placeholder="https://kreis.fox112.de" class="min-w-0 flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
            <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-fw-navy px-4 py-2 text-sm font-semibold text-white">Speichern und einlesen</button>
        </div>
        @error('foxUrl')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
    </form>

    <form wire:submit="add" class="flex flex-wrap items-start gap-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <div class="min-w-0 flex-1">
            <input type="text" wire:model="newName" maxlength="150" placeholder="Lehrgang ergänzen, z. B. Vegetationsbrandbekämpfung" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
            @error('newName')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
        <button type="submit" class="rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white hover:bg-fw-red-dark">Hinzufügen</button>
    </form>

    @if($suggestions->isNotEmpty())
        <section class="space-y-2 rounded-2xl border border-amber-200 bg-amber-50 p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-amber-900">Vorschläge aus fox112 ({{ $suggestions->count() }})</h2>
                <button type="button" wire:click="acceptAll" wire:confirm="Alle {{ $suggestions->count() }} Vorschläge anbieten?" class="text-xs text-amber-900 underline">Alle anbieten</button>
            </div>
            <ul class="divide-y divide-amber-100 text-sm">
                @foreach($suggestions as $s)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-1.5" wire:key="sg-{{ $s->id }}">
                        <span class="text-gray-900">{{ $s->name }}@if($s->removed_at)<span class="text-xs text-gray-500"> · von dir gelöscht, fox112 führt ihn weiter</span>@endif @if($s->variants())<span class="block text-xs text-gray-500">{{ implode(', ', $s->variants()) }}</span>@endif</span>
                        <span class="flex gap-2"><button type="button" wire:click="accept({{ $s->id }})" class="rounded-lg bg-fw-navy px-3 py-1 text-xs font-semibold text-white">Anbieten</button>
                            <button type="button" wire:click="ignore({{ $s->id }})" class="rounded-lg border border-gray-300 bg-white px-3 py-1 text-xs font-semibold text-gray-700">Nicht anbieten</button></span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if($gone->isNotEmpty())
        <section class="space-y-2 rounded-2xl border border-amber-200 bg-white p-4">
            <h2 class="text-sm font-semibold text-gray-900">Nicht mehr in fox112 ({{ $gone->count() }})</h2>
            <p class="text-xs text-gray-500">Der Kreis führt diese angebotenen Lehrgänge gerade nicht mehr – oft nur vorübergehend. Bis du entscheidest, bleiben sie angeboten.</p>
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach($gone as $g)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-1.5" wire:key="gn-{{ $g->id }}">
                        <span class="text-gray-900">{{ $g->name }} <span class="text-xs text-gray-500">· seit {{ $g->gone_since->setTimezone('Europe/Berlin')->format('d.m.Y') }}{{ $g->open_count ? ' · '.$g->open_count.' mit Interesse' : '' }}</span></span>
                        <span class="flex gap-2"><button type="button" wire:click="keep({{ $g->id }})" class="rounded-lg bg-fw-navy px-3 py-1 text-xs font-semibold text-white">Weiter anbieten</button>
                            <button type="button" wire:click="remove({{ $g->id }})" wire:confirm="„{{ $g->name }}“ von der Lehrgangsliste löschen?" class="rounded-lg border border-gray-300 bg-white px-3 py-1 text-xs font-semibold text-red-700">Entfernen</button></span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @error('repeat')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
    <section>
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-gray-700">Alle Lehrgänge</h2>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Lehrgang suchen" class="w-full sm:w-64 rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-fw-red focus:ring-fw-red">
        </div>
        <ul class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100">
            @forelse($courses as $course)
                <li class="flex flex-wrap items-center justify-between gap-2 p-3 text-sm" wire:key="cl-{{ $course->id }}">
                    <div class="min-w-0">
                        <p class="font-medium {{ $course->isOffered() ? 'text-gray-900' : 'text-gray-400' }}">{{ $course->name }}</p>
                        <p class="text-xs text-gray-500">
                            @if($course->listed)<span class="text-green-700">in fox112</span>@elseif($course->titles)nicht in fox112 (zuletzt {{ $course->last_seen_at?->setTimezone('Europe/Berlin')->format('d.m.Y') ?? '–' }})@else von Hand ergänzt @endif
                            @if($course->open_count) · {{ $course->open_count }} mit Interesse @endif
                            @unless($course->isOffered()) · wird nicht angeboten @endunless
                        </p>
                    </div>
                    <label class="flex items-center gap-1 text-xs text-gray-600" title="Leer = einmal absolviert gilt für immer">Auffrischung alle
                        <input type="number" min="1" max="120" value="{{ $course->repeat_months }}" wire:change="setRepeat({{ $course->id }}, $event.target.value)" class="w-16 rounded border-gray-300 px-1 py-0.5 text-xs" aria-label="Auffrischung in Monaten"> Monate</label>
                    <button type="button" role="switch" aria-checked="{{ $course->is_standard ? 'true' : 'false' }}" wire:click="toggle({{ $course->id }})"
                            class="inline-flex items-center gap-2 text-xs text-gray-600">
                        <span class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors {{ $course->is_standard ? 'bg-fw-red' : 'bg-gray-300' }}">
                            <span class="inline-block h-5 w-5 rounded-full bg-white shadow transition-transform {{ $course->is_standard ? 'translate-x-5' : 'translate-x-0.5' }}"></span>
                        </span>
                        angeboten
                    </button>
                    <button type="button" wire:click="remove({{ $course->id }})" wire:confirm="„{{ $course->name }}“ von der Lehrgangsliste löschen?{{ $course->open_count ? ' '.$course->open_count.' Kameraden haben Interesse gemeldet – ihre Einträge bleiben.' : '' }}" class="text-xs text-red-600 underline">Löschen</button>
                </li>
            @empty
                <li class="p-4 text-sm text-gray-500">{{ $search !== '' ? 'Kein Lehrgang passt zur Suche.' : 'Noch keine Lehrgänge.' }}</li>
            @endforelse
        </ul>
    </section>
    @if($ignored->isNotEmpty())
        <details class="text-sm">
            <summary class="cursor-pointer text-gray-500">Nicht angeboten und nicht mehr vorgeschlagen ({{ $ignored->count() }})</summary>
            <ul class="mt-2 divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white">
                @foreach($ignored as $i)
                    <li class="flex items-center justify-between gap-2 p-2" wire:key="ig-{{ $i->id }}"><span class="text-gray-600">{{ $i->name }}</span>
                        <span class="flex gap-3 text-xs"><button type="button" wire:click="accept({{ $i->id }})" class="text-fw-navy underline">Anbieten</button><button type="button" wire:click="unignore({{ $i->id }})" class="text-gray-600 underline">Doch wieder vorschlagen</button></span></li>
                @endforeach
            </ul>
        </details>
    @endif
</div>

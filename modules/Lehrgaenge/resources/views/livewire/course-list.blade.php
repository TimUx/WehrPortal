<div class="space-y-6">
    <div>
        <h1 class="text-xl font-bold text-gray-900">Lehrgangsliste</h1>
        <p class="text-sm text-gray-500">Kameraden können Interesse an jedem Lehrgang melden, der gerade in fox112 steht oder auf der <strong>Standardliste</strong> ist. Neue Lehrgänge aus fox112 kommen automatisch auf die Standardliste – so bleiben sie wählbar, wenn der Kreis sie zeitweise aus fox112 nimmt. Fehlt ein Lehrgang ganz, hier von Hand ergänzen.</p>
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
                            @if($course->listed)<span class="text-green-700">in fox112</span>@elseif($course->titles)nicht mehr in fox112 (zuletzt {{ $course->last_seen_at?->setTimezone('Europe/Berlin')->format('d.m.Y') ?? '–' }})@else von Hand ergänzt @endif
                            @if($course->open_count) · {{ $course->open_count }} mit Interesse @endif
                            @unless($course->isOffered()) · wird nicht angeboten @endunless
                        </p>
                    </div>
                    <button type="button" role="switch" aria-checked="{{ $course->is_standard ? 'true' : 'false' }}" wire:click="toggle({{ $course->id }})"
                            class="inline-flex items-center gap-2 text-xs text-gray-600">
                        <span class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors {{ $course->is_standard ? 'bg-fw-red' : 'bg-gray-300' }}">
                            <span class="inline-block h-5 w-5 rounded-full bg-white shadow transition-transform {{ $course->is_standard ? 'translate-x-5' : 'translate-x-0.5' }}"></span>
                        </span>
                        Standardliste
                    </button>
                </li>
            @empty
                <li class="p-4 text-sm text-gray-500">{{ $search !== '' ? 'Kein Lehrgang passt zur Suche.' : 'Noch keine Lehrgänge.' }}</li>
            @endforelse
        </ul>
    </section>
</div>

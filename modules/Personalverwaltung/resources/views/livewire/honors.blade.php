@php($input = 'rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
@php($card = 'space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm')
<div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Ehrungen</h1>
            <p class="text-sm text-gray-500">Wer im Jahr eine Ehrung erreicht – nach Eintrittsdatum und den Dienstjahren der Ehrungsart. Bereit für die Jahreshauptversammlung.</p>
        </div>
        <label class="text-sm text-gray-700">Jahr <input type="number" wire:model.live="year" min="1990" max="2100" class="{{ $input }} w-28"></label>
    </div>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @foreach(['personal', 'honor_title', 'honor_date', 'type_name', 'type_years'] as $f)@error($f)<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror @endforeach

    <section class="{{ $card }}">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-gray-900">Fällig {{ $year }} ({{ $due->count() }})</h2>
            <label class="text-xs text-gray-600">Verliehen am <input type="date" wire:model="awardDate" class="{{ $input }}"></label>
        </div>
        <ul class="divide-y divide-gray-100">
            @forelse($due as $row)
                <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm" wire:key="d-{{ $row['user']->id }}-{{ $row['type']->id }}">
                    <span><a href="{{ route('personalverwaltung.member', $row['user']) }}" class="font-medium text-gray-900 hover:underline">{{ $row['user']->name }}</a>
                        <span class="text-gray-600">· {{ $row['type']->name }}</span>
                        <span class="block text-xs {{ $row['overdue'] ? 'font-semibold text-red-700' : 'text-gray-500' }}">{{ $row['type']->after_years }} Dienstjahre am {{ $row['reached_on']->format('d.m.Y') }}{{ $row['overdue'] ? ' – schon überfällig' : '' }}</span></span>
                    <button type="button" wire:click="award({{ $row['user']->id }}, {{ $row['type']->id }})" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Verliehen</button>
                </li>
            @empty
                <li class="py-2 text-sm text-gray-500">{{ $types->whereNotNull('after_years')->isEmpty() ? 'Lege unten Ehrungen mit Dienstjahren an, dann erscheinen hier die fälligen.' : 'In diesem Jahr ist keine Ehrung fällig.' }}</li>
            @endforelse
        </ul>
    </section>

    @if($birthdays->isNotEmpty())
        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold text-gray-900">Runde Geburtstage {{ $year }} ({{ $birthdays->count() }})</h2>
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach($birthdays as $row)
                    <li class="flex flex-wrap justify-between gap-2 py-1.5"><a href="{{ route('personalverwaltung.member', $row['user']) }}" class="font-medium text-gray-900 hover:underline">{{ $row['user']->name }}</a><span class="text-gray-600">{{ $row['age'] }} Jahre am {{ $row['on']->format('d.m.') }}</span></li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="{{ $card }}">
        <h2 class="text-sm font-semibold text-gray-900">Verliehen {{ $year }} ({{ $awarded->count() }})</h2>
        <ul class="divide-y divide-gray-100 text-sm">
            @forelse($awarded as $h)
                <li class="flex flex-wrap justify-between gap-2 py-1.5"><span><a href="{{ route('personalverwaltung.member', $h->user) }}" class="font-medium text-gray-900 hover:underline">{{ $h->user->name }}</a> · {{ $h->title }}</span><span class="text-xs text-gray-500">{{ $h->awarded_on->format('d.m.Y') }}</span></li>
            @empty
                <li class="py-1.5 text-gray-500">Noch keine.</li>
            @endforelse
        </ul>
    </section>

    <section class="{{ $card }}">
        <h2 class="text-sm font-semibold text-gray-900">Ehrungsarten</h2>
        <p class="text-xs text-gray-500">Mit Dienstjahren (z. B. 10, 25, 40) schlägt openwehr die Ehrung vor, sobald ein Kamerad sie erreicht. Ohne Dienstjahre (z. B. Leistungsabzeichen) wird sie nur von Hand eingetragen.</p>
        <ul class="divide-y divide-gray-100 text-sm">
            @foreach($types as $t)
                <li class="flex flex-wrap items-center justify-between gap-2 py-1.5" wire:key="t-{{ $t->id }}">
                    <span>{{ $t->name }}@if($t->after_years)<span class="text-gray-500"> · nach {{ $t->after_years }} Dienstjahren</span>@endif</span>
                    <span class="flex gap-3 text-xs"><button type="button" wire:click="editType({{ $t->id }})" class="text-fw-navy underline">Ändern</button>
                        <button type="button" wire:click="copyType({{ $t->id }})" class="text-fw-navy underline">Kopieren</button>
                        <button type="button" wire:click="deleteType({{ $t->id }})" wire:confirm="„{{ $t->name }}“ löschen?" class="text-red-600 underline">Löschen</button></span>
                </li>
            @endforeach
        </ul>
        <button type="button" wire:click="addPresets" class="text-xs text-fw-navy underline">Übliche Ehrungsarten anlegen (Ehrenzeichen 10–60 Jahre, Leistungsabzeichen …)</button>
        <form wire:submit="saveType" class="flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3">
            <label class="block min-w-0 flex-1 text-xs text-gray-600">{{ $editingType ? 'Ehrung ändern' : 'Neue Ehrung' }}<input type="text" wire:model="type.name" maxlength="150" placeholder="z. B. Feuerwehr-Ehrenzeichen 25 Jahre" class="{{ $input }} mt-1 w-full"></label>
            <label class="block w-36 text-xs text-gray-600">nach Dienstjahren<input type="number" min="1" max="80" wire:model="type.after_years" class="{{ $input }} mt-1 w-full"></label>
            <button type="submit" class="rounded-lg bg-fw-navy px-3 py-2 text-sm font-semibold text-white">Speichern</button>
            @if($editingType)<button type="button" wire:click="$set('editingType', null)" class="text-sm text-gray-500 underline">Abbrechen</button>@endif
        </form>
    </section>
</div>

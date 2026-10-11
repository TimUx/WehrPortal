@php($input = 'mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
@php($error = 'text-sm text-red-600')
<form wire:submit="save" class="mx-auto max-w-2xl space-y-4">
    <h1 class="text-xl font-bold text-gray-900">{{ $template ? 'Vorlage ändern' : 'Neue Wartungsvorlage' }}</h1>
    @error('wartung')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror

    <section class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <label class="block text-sm text-gray-700">Name<input type="text" wire:model="name" maxlength="150" placeholder="z. B. Jahresprüfung Feuerwehrleine" class="{{ $input }}"></label>
        @error('name')<p class="{{ $error }}">{{ $message }}</p>@enderror
        <label class="block text-sm text-gray-700">Beschreibung / Grundlage (optional)<textarea wire:model="description" rows="2" maxlength="2000" placeholder="z. B. nach DGUV Grundsatz 305-002 und Herstellerangaben" class="{{ $input }}"></textarea></label>
    </section>

    <section class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">Für welche Geräte?</p>
        <label class="block text-sm text-gray-700">Alle Geräte der Kategorie
            <select wire:model.live="categoryId" class="{{ $input }}">
                <option value="">– keine Kategorie –</option>
                @foreach($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}{{ $c->lifetime_years ? ' (Lebensdauer '.$c->lifetime_years.' J.)' : '' }}</option>@endforeach
            </select>
        </label>
        @if($categoryId !== '')<p class="text-xs text-gray-500">{{ $categoryCount }} Geräte im Dienst in dieser Kategorie.</p>@endif
        @error('category_id')<p class="{{ $error }}">{{ $message }}</p>@enderror
        <p class="text-sm text-gray-700">… und/oder einzelne Geräte</p>
        @if($picked->isNotEmpty())
            <ul class="flex flex-wrap gap-2 text-xs">
                @foreach($picked as $d)
                    <li class="flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-1">{{ $d->code }} {{ $d->name }}<button type="button" wire:click="toggleDevice({{ $d->id }})" class="text-gray-500" aria-label="entfernen">×</button></li>
                @endforeach
            </ul>
        @endif
        <input type="search" wire:model.live.debounce.300ms="deviceSearch" placeholder="Gerät suchen (Code oder Name)" class="{{ $input }}">
        @if($matches->isNotEmpty())
            <ul class="divide-y divide-gray-100 rounded-lg border border-gray-200 text-sm">
                @foreach($matches as $d)
                    <li><label class="flex items-center gap-2 px-3 py-1.5"><input type="checkbox" wire:click="toggleDevice({{ $d->id }})" @checked(in_array($d->id, $equipmentIds, true)) class="rounded"> {{ $d->code }} – {{ $d->name }}</label></li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">Intervall</p>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="block text-sm text-gray-700">Wiederholung alle … Monate (leer = nur bei Bedarf)<input type="number" min="1" max="240" wire:model="intervalMonths" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Voranzeige: … Tage vorher anzeigen<input type="number" min="0" max="365" wire:model="leadDays" class="{{ $input }}"></label>
        </div>
        @error('interval_months')<p class="{{ $error }}">{{ $message }}</p>@enderror
        @error('lead_days')<p class="{{ $error }}">{{ $message }}</p>@enderror
        <label class="flex items-start gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="countsAsInspection" class="mt-0.5 rounded text-fw-red focus:ring-fw-red"> <span>Gilt als Prüfung: Ein Protokoll setzt die nächste Prüffrist am Gerät in der Ausrüstungsverwaltung (bei „nicht bestanden“ wird das Gerät gesperrt).</span></label>
    </section>

    <section class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">Checkliste</p>
        <ol class="space-y-1 text-sm">
            @foreach($checklist as $i => $item)
                <li class="flex items-center justify-between gap-2 rounded-lg bg-gray-50 px-3 py-1.5" wire:key="i-{{ $i }}-{{ md5($item) }}">
                    <span>{{ $i + 1 }}. {{ $item }}</span>
                    <span class="flex shrink-0 gap-2 text-xs text-gray-500">
                        <button type="button" wire:click="moveItem({{ $i }}, -1)" aria-label="nach oben" @disabled($i === 0)>↑</button>
                        <button type="button" wire:click="moveItem({{ $i }}, 1)" aria-label="nach unten" @disabled($i === count($checklist) - 1)>↓</button>
                        <button type="button" wire:click="removeItem({{ $i }})" class="underline">entfernen</button>
                    </span>
                </li>
            @endforeach
        </ol>
        <textarea wire:model="newItem" rows="2" placeholder="Prüfpunkt(e) – einer pro Zeile, z. B. „Mantel ohne Beschädigung“" class="{{ $input }}"></textarea>
        <button type="button" wire:click="addItem" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">Hinzufügen</button>
        @error('checklist')<p class="{{ $error }}">{{ $message }}</p>@enderror
    </section>

    <section class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">Wer prüft?</p>
        <div class="flex gap-2">
            @foreach(\Modules\Wartung\src\Models\Template::PERFORMERS as $value => $label)
                <label class="flex flex-1 items-center justify-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm has-[:checked]:border-fw-red has-[:checked]:bg-red-50"><input type="radio" wire:model.live="performer" value="{{ $value }}"> {{ $label }}</label>
            @endforeach
        </div>
        @if($performer === 'external')
            <label class="block text-sm text-gray-700">Fachfirma (Vorschlag)
                <select wire:model="providerId" class="{{ $input }}">
                    <option value="">– offen –</option>
                    @foreach($providers as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                </select>
            </label>
        @else
            <label class="block text-sm text-gray-700">Nötige Qualifikation (optional)<input type="text" wire:model="requiredQualification" maxlength="150" placeholder="z. B. Gerätewart Atemschutz – genau wie unter „Prüfer“" class="{{ $input }}"></label>
        @endif
        <label class="block text-sm text-gray-700">Geschätzter Zeitaufwand in Minuten (optional)<input type="number" min="0" max="6000" wire:model="estimatedMinutes" class="{{ $input }}"></label>
        @error('provider_id')<p class="{{ $error }}">{{ $message }}</p>@enderror
        @error('estimated_minutes')<p class="{{ $error }}">{{ $message }}</p>@enderror
        <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="isActive" class="rounded text-fw-red focus:ring-fw-red"> Aktiv (erzeugt Wartungstermine)</label>
    </section>

    <div class="flex flex-wrap items-center gap-3">
        <button type="submit" class="rounded-lg bg-fw-red px-5 py-2.5 text-sm font-semibold text-white hover:bg-fw-red-dark">Speichern</button>
        <a href="{{ route('wartung.templates') }}" class="text-sm text-gray-500 underline">Abbrechen</a>
        @if($template)
            <button type="button" wire:click="delete" wire:confirm="Vorlage löschen? Hat sie schon Protokolle, wird sie nur ausgeschaltet." class="ml-auto text-sm text-red-600 underline">Löschen</button>
        @endif
    </div>
</form>

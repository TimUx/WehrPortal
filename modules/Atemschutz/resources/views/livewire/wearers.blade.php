@php($input = 'mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red')
@php($select = 'rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-fw-red focus:ring-fw-red')
@php($fmt = fn ($d) => $d?->format('d.m.Y') ?? '–')
<div class="space-y-4">
    <div>
        <h1 class="text-xl font-bold text-gray-900">Atemschutzgeräteträger</h1>
        <p class="text-sm text-gray-500">Einsatzbereit ist, wer den AGT-Lehrgang hat, eine gültige G26.3 und in den letzten {{ \Modules\Atemschutz\src\Services\ReadinessService::VALID_MONTHS }} Monaten Unterweisung und Belastungsübung (FwDV 7). Gespeichert werden nur Daten, keine Befunde. Absolvierte Lehrgänge „Atemschutzgeräteträger…“ und Belastungsübungen aus dem Modul Lehrgänge zählen mit.</p>
    </div>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif

    <div class="flex flex-wrap items-center gap-2">
        <select wire:model.live="filter" class="{{ $select }}" aria-label="Filter">
            <option value="">Alle aktiven ({{ $counts['ready'] + $counts['soon'] + $counts['not_ready'] }})</option>
            <option value="ready">Einsatzbereit ({{ $counts['ready'] }})</option>
            <option value="soon">Frist läuft ab ({{ $counts['soon'] }})</option>
            <option value="not_ready">Nicht einsatzbereit ({{ $counts['not_ready'] }})</option>
            <option value="inactive">Nicht mehr im Pool</option>
        </select>
        @if($groups->isNotEmpty())
            <select wire:model.live="group" class="{{ $select }}" aria-label="Abteilung">
                <option value="">Alle Abteilungen</option>
                @foreach($groups as $g)<option value="{{ $g->id }}">{{ $g->name }}</option>@endforeach
            </select>
        @endif
        <select wire:model="addUserId" class="{{ $select }}" aria-label="Kamerad hinzufügen">
            <option value="">Kamerad in den Pool aufnehmen …</option>
            @foreach($candidates as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
        </select>
        <button type="button" wire:click="addWearer" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700">Aufnehmen</button>
        @error('addUserId')<span class="text-sm text-red-600">{{ $message }}</span>@enderror
    </div>

    @if($editingWearer)
        <form wire:submit="save" class="space-y-3 rounded-2xl border border-fw-navy bg-white p-4 shadow-sm">
            <p class="text-sm font-semibold text-gray-900">{{ $editingWearer->user->name }}</p>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="form.is_agt" class="rounded"> AGT-Lehrgang absolviert</label>
                <label class="block text-sm text-gray-700">am (optional)<input type="date" wire:model="form.agt_qualified_on" class="{{ $input }}"></label>
                <label class="block text-sm text-gray-700">G26.3 untersucht am<input type="date" wire:model.live="form.g263_examined_on" class="{{ $input }}"></label>
                <label class="block text-sm text-gray-700">G26.3 gültig bis<input type="date" wire:model="form.g263_valid_until" class="{{ $input }}"></label>
                <label class="block text-sm text-gray-700">RFID-Chip<input type="text" wire:model="form.rfid_uid" maxlength="100" autocomplete="off" placeholder="Feld antippen, Chip an den Leser halten" class="{{ $input }}"></label>
                <label class="block text-sm text-gray-700">Notiz (keine Befunde!)<input type="text" wire:model="form.notes" maxlength="500" class="{{ $input }}"></label>
            </div>
            @foreach(['form.agt_qualified_on', 'form.g263_examined_on', 'form.g263_valid_until', 'form.rfid_uid', 'form.notes'] as $field)@error($field)<p class="text-sm text-red-600">{{ $message }}</p>@enderror @endforeach
            <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="form.is_active" class="rounded"> Im Atemschutz-Pool</label>
            <div class="flex gap-3"><button type="submit" class="rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white">Speichern</button><button type="button" wire:click="cancel" class="text-sm text-gray-500 underline">Schließen</button></div>
            @if($trainings->isNotEmpty())
                <ul class="space-y-1 border-t border-gray-100 pt-2 text-xs text-gray-600">
                    @foreach($trainings as $t)
                        <li class="flex justify-between gap-2">{{ $t->done_on->format('d.m.Y') }} · {{ $t->kindLabel() }}{{ $t->notes ? ' · '.$t->notes : '' }}<button type="button" wire:click="deleteTraining({{ $t->id }})" wire:confirm="Eintrag löschen?" class="underline">löschen</button></li>
                    @endforeach
                </ul>
            @endif
        </form>
    @endif

    <section class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">Unterweisung, Belastungsübung oder Übung eintragen</p>
        <div class="flex flex-wrap items-center gap-2">
            <select wire:model="trainingKind" class="{{ $select }}">@foreach(\Modules\Atemschutz\src\Models\Training::KINDS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
            <input type="date" wire:model="trainingDate" class="{{ $select }}">
            <input type="text" wire:model="trainingNotes" maxlength="300" placeholder="Notiz, z. B. Übungsstrecke Kreisfeuerwehrzentrale" class="{{ $select }} w-full sm:w-72">
            <button type="button" wire:click="recordTraining" class="rounded-lg bg-fw-navy px-3 py-1.5 text-sm font-semibold text-white">Für {{ count($selected) }} eintragen</button>
            <button type="button" wire:click="selectAll" class="text-xs text-gray-500 underline">alle wählen</button>
        </div>
        @foreach(['trainingKind', 'trainingDate', 'selected'] as $field)@error($field)<p class="text-sm text-red-600">{{ $message }}</p>@enderror @endforeach
    </section>

    <div class="overflow-x-auto rounded-2xl border border-gray-100 bg-white shadow-sm">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs text-gray-500">
                <tr><th class="p-2"></th><th class="p-2">Name</th><th class="p-2">Status</th>@foreach(\Modules\Atemschutz\src\Services\ReadinessService::ITEMS as $label)<th class="p-2">{{ $label }}</th>@endforeach<th class="p-2">Chip</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($states as $s)
                    <tr wire:key="w-{{ $s['wearer']->id }}">
                        <td class="p-2"><input type="checkbox" wire:model.live="selected" value="{{ $s['wearer']->user_id }}" class="rounded" aria-label="auswählen"></td>
                        <td class="p-2"><button type="button" wire:click="edit({{ $s['wearer']->id }})" class="font-medium text-fw-navy underline">{{ $s['wearer']->user->name }}</button>
                            @if($s['wearer']->user->rank || $s['wearer']->user->groups->isNotEmpty())<span class="block text-[11px] text-gray-500">{{ collect([$s['wearer']->user->rank, $s['wearer']->user->groups->pluck('name')->implode(', ')])->filter()->implode(' · ') }}</span>@endif</td>
                        <td class="p-2 text-xs font-semibold {{ $s['ready'] ? ($s['soon'] ? 'text-amber-800' : 'text-green-700') : 'text-red-700' }}">{{ $s['ready'] ? ($s['soon'] ? 'läuft ab' : 'bereit') : 'nicht bereit' }}</td>
                        @foreach($s['items'] as $key => $item)
                            <td class="p-2 text-xs {{ $item['ok'] ? ($item['until'] && $item['until']->lte(now()->addDays(\Modules\Atemschutz\src\Services\ReadinessService::SOON_DAYS)) ? 'text-amber-800' : 'text-gray-700') : (in_array($key, \Modules\Atemschutz\src\Services\ReadinessService::BLOCKING) ? 'font-semibold text-red-700' : 'text-gray-400') }}">
                                @if($key === 'agt'){{ $item['ok'] ? 'ja' : 'nein' }}@else{{ $item['until'] ? 'bis '.$fmt($item['until']) : 'fehlt' }}@endif
                                @if($item['source'])<span class="block text-[10px] text-gray-400">aus {{ $item['source'] }}</span>@endif
                            </td>
                        @endforeach
                        <td class="p-2 text-xs text-gray-500">{{ $s['wearer']->rfid_uid ? '✓' : '–' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="p-4 text-gray-500">Noch niemand im Atemschutz-Pool.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <section class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">Berechnungsgrundlagen</p>
        <p class="text-sm text-gray-600">Erwartete Einsatzzeit = (niedrigster Startdruck − Reserve) × Flaschenvolumen ÷ Luftverbrauch. Rückzugsdruck nach Erreichen des Ziels: doppelter Verbrauch des Hinwegs, mindestens die Reserve.</p>
        <div class="flex flex-wrap items-end gap-3">
            <label class="text-sm text-gray-700">Flasche (Liter)<input type="text" inputmode="decimal" wire:model="liters" class="{{ $input }} w-24"></label>
            <label class="text-sm text-gray-700">Verbrauch (l/min)<input type="number" wire:model="consumption" class="{{ $input }} w-24"></label>
            <label class="text-sm text-gray-700">Reserve (bar)<input type="number" wire:model="reserve" class="{{ $input }} w-24"></label>
            <button type="button" wire:click="saveSettings" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700">Speichern</button>
        </div>
        @foreach(['cylinder_liters', 'consumption_lpm', 'reserve_bar'] as $field)@error($field)<p class="text-sm text-red-600">{{ $message }}</p>@enderror @endforeach
    </section>
</div>

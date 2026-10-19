{{-- Absolvierte Lehrgänge of one Kamerad; with $canManage (admin, Ausbildungsleiter) they are corrected and deleted here --}}
<div class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100 text-sm">
    @foreach($records as $record)
        <div class="p-3" wire:key="done-{{ $record->id }}">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span><span class="font-medium text-gray-900">{{ $record->course->name }}</span>
                    @if($record->entered_manually && $record->notes)<span class="block text-xs text-gray-500">{{ $record->notes }}</span>@endif</span>
                <span class="flex items-center gap-3 text-gray-500">absolviert {{ $record->completed_on?->format('d.m.Y') ?? '(Datum unbekannt)' }}{{ $record->entered_manually ? ' · nachgetragen' : '' }}
                    @if($record->validUntil())<span class="{{ ['expired' => 'font-semibold text-red-700', 'soon' => 'text-amber-700'][$record->refreshState()] ?? '' }}">· gültig bis {{ $record->validUntil()->format('d.m.Y') }}</span>@endif
                    @if($canManage ?? false)
                        <button type="button" wire:click="startEdit({{ $record->id }})" class="text-xs text-fw-navy underline">Bearbeiten</button>
                        <button type="button" wire:click="startDelete({{ $record->id }})" class="text-xs text-red-600 underline">Löschen</button>
                    @endif
                </span>
            </div>
            @if(($canManage ?? false) && ($editingId ?? null) === $record->id)
                <form wire:submit="saveEdit" class="mt-2 space-y-2 rounded-xl bg-gray-50 p-3">
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                        <input type="text" wire:model="edit.course" list="lq-course-names" maxlength="150" class="sm:col-span-2 w-full rounded-lg border-gray-300 text-sm" aria-label="Lehrgang">
                        <input type="date" wire:model="edit.completed_on" max="{{ now('Europe/Berlin')->toDateString() }}" class="w-full rounded-lg border-gray-300 text-sm" aria-label="absolviert am">
                    </div>
                    <input type="text" wire:model="edit.note" maxlength="500" placeholder="Bemerkung (optional)" class="w-full rounded-lg border-gray-300 text-sm">
                    @foreach(['entry_course', 'entry_date'] as $f)@error($f)<p class="text-sm text-red-600">{{ $message }}</p>@enderror @endforeach
                    <div class="flex gap-3"><button type="submit" class="rounded-lg bg-fw-navy px-3 py-1.5 text-sm font-semibold text-white">Speichern</button><button type="button" wire:click="cancel" class="text-sm text-gray-500 underline">Abbrechen</button></div>
                </form>
            @endif
            @if(($canManage ?? false) && ($deletingId ?? null) === $record->id)
                <form wire:submit="confirmDelete" class="mt-2 space-y-2 rounded-xl bg-red-50 p-3">
                    <p class="text-sm text-red-900">„{{ $record->course->name }}“ löschen? Mit Begründung bleibt dokumentiert, was gelöscht wurde und warum – ohne Begründung ist der Eintrag einfach weg.</p>
                    <textarea wire:model="deleteReason" rows="2" maxlength="1000" placeholder="Begründung (optional), z. B. „versehentlich beim falschen Kameraden eingetragen“" class="w-full rounded-lg border-gray-300 text-sm"></textarea>
                    <div class="flex gap-3"><button type="submit" class="rounded-lg bg-red-600 px-3 py-1.5 text-sm font-semibold text-white">Löschen</button><button type="button" wire:click="cancel" class="text-sm text-gray-500 underline">Abbrechen</button></div>
                </form>
            @endif
        </div>
    @endforeach
</div>

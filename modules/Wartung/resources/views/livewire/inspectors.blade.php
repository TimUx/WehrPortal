@php($input = 'mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red')
<div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Prüfer & Qualifikationen</h1>
            <p class="text-sm text-gray-500">Wer welche Prüfungen machen darf, mit Prüfer-ID und Gültigkeit. Eine Vorlage kann eine Qualifikation verlangen; ins Protokoll kommen Qualifikation und Prüfer-ID mit. Damit Kameraden Wartungen durchführen können, brauchen sie im Modul die Rolle „Prüfer“ (Admin → Module).</p>
        </div>
        @if($editing === null)<button type="button" wire:click="edit" class="rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white hover:bg-fw-red-dark">Qualifikation eintragen</button>@endif
    </div>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif

    @if($editing !== null)
        <form wire:submit="save" class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <label class="block text-sm text-gray-700">Kamerad
                <select wire:model="form.user_id" class="{{ $input }}">
                    <option value="">– bitte wählen –</option>
                    @foreach($members as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach
                </select>
            </label>
            @error('qualification_user')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <label class="block text-sm text-gray-700">Qualifikation<input type="text" wire:model="form.name" list="wm-qualifications" maxlength="150" placeholder="z. B. Gerätewart Atemschutz, Sachkundiger PSAgA, Elektrofachkraft" class="{{ $input }}"></label>
            <datalist id="wm-qualifications">@foreach($names as $n)<option value="{{ $n }}">@endforeach</datalist>
            @error('qualification_name')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <label class="block text-sm text-gray-700">Prüfer-ID (optional)<input type="text" wire:model="form.inspector_number" maxlength="50" class="{{ $input }}"></label>
                <label class="block text-sm text-gray-700">Gültig bis (optional)<input type="date" wire:model="form.valid_until" class="{{ $input }}"></label>
            </div>
            @error('qualification_valid_until')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <label class="block text-sm text-gray-700">Nachweis / Notiz (optional)<input type="text" wire:model="form.notes" maxlength="300" placeholder="z. B. Lehrgang an der Landesfeuerwehrschule 03/2026" class="{{ $input }}"></label>
            @error('wartung')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <div class="flex gap-3"><button type="submit" class="rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white">Speichern</button><button type="button" wire:click="cancel" class="text-sm text-gray-500 underline">Abbrechen</button></div>
        </form>
    @endif

    <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
        @forelse($qualifications as $userId => $list)
            <div class="p-3 text-sm" wire:key="u-{{ $userId }}">
                <p class="font-medium text-gray-900">{{ $list->first()->user?->name }}
                    @if($s = $stats[$userId] ?? null)<span class="text-xs font-normal text-gray-500"> · {{ $s->n }} Protokolle, zuletzt {{ \Illuminate\Support\Carbon::parse($s->last)->format('d.m.Y') }} · <a href="{{ route('wartung.protocols', ['suche' => $list->first()->user?->name, 'jahr' => '']) }}" class="underline">ansehen</a></span>@endif
                </p>
                <ul class="mt-1 space-y-1">
                    @foreach($list as $q)
                        <li class="flex items-center justify-between gap-3" wire:key="q-{{ $q->id }}">
                            <span class="{{ $q->isValid() ? 'text-gray-700' : 'text-red-700' }}">{{ $q->label() }}{{ $q->valid_until ? ($q->isValid() ? ', gültig bis ' : ', abgelaufen am ').$q->valid_until->format('d.m.Y') : '' }}@if($q->notes)<span class="block text-xs text-gray-500">{{ $q->notes }}</span>@endif</span>
                            <span class="flex shrink-0 gap-3 text-xs"><button type="button" wire:click="edit({{ $q->id }})" class="text-fw-navy underline">ändern</button><button type="button" wire:click="delete({{ $q->id }})" wire:confirm="Qualifikation entfernen?" class="text-red-600 underline">entfernen</button></span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @empty
            <p class="p-4 text-sm text-gray-500">Noch keine Qualifikationen eingetragen.</p>
        @endforelse
    </div>
</div>

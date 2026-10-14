@php($input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red')
<div class="max-w-2xl mx-auto">
    <a href="{{ route('kalender.polls') }}" class="inline-flex items-center gap-1 rounded-lg py-1.5 pr-2 text-sm font-medium text-fw-navy hover:text-fw-red">← Terminabfragen</a>
    <h1 class="text-xl font-bold text-gray-900 mt-1 mb-4">Neue Terminabfrage</h1>
    <form wire:submit="save" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <label class="block text-sm font-medium text-gray-700 sm:col-span-2">Wofür? <span class="text-red-600">*</span>
                <input type="text" wire:model="title" maxlength="150" placeholder="z. B. Arbeitsdienst Frühjahrsputz" class="mt-1 {{ $input }}">
                @error('title')<span class="text-sm text-red-600">{{ $message }}</span>@enderror
            </label>
            <label class="block text-sm font-medium text-gray-700">Art
                <select wire:model="type" class="mt-1 {{ $input }}">@foreach($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
            </label>
        </div>
        <label class="block text-sm font-medium text-gray-700">Ort<input type="text" wire:model="location" list="openwehr-places" maxlength="150" class="mt-1 {{ $input }}"></label>
        <label class="block text-sm font-medium text-gray-700">Beschreibung<textarea wire:model="description" rows="2" maxlength="5000" class="mt-1 {{ $input }}"></textarea></label>

        <div class="space-y-2">
            <p class="text-sm font-medium text-gray-700">Terminvorschläge</p>
            @foreach($options as $i => $o)
                <div class="flex flex-wrap items-center gap-2 rounded-lg bg-gray-50 p-2" wire:key="o-{{ $i }}">
                    <input type="date" wire:model="options.{{ $i }}.date" class="rounded-lg border border-gray-300 px-2 py-1.5 text-sm" aria-label="Datum">
                    @unless($o['all_day'])
                        <input type="time" wire:model="options.{{ $i }}.from" class="rounded-lg border border-gray-300 px-2 py-1.5 text-sm" aria-label="Beginn">
                        <span class="text-gray-400">–</span>
                        <input type="time" wire:model="options.{{ $i }}.until" class="rounded-lg border border-gray-300 px-2 py-1.5 text-sm" aria-label="Ende">
                    @endunless
                    <label class="inline-flex items-center gap-1 text-xs text-gray-600"><input type="checkbox" wire:model.live="options.{{ $i }}.all_day" class="rounded"> ganztägig</label>
                    @if(count($options) > 2)<button type="button" wire:click="removeOption({{ $i }})" class="ml-auto text-xs text-gray-500 underline">entfernen</button>@endif
                </div>
            @endforeach
            <button type="button" wire:click="addOption" class="text-sm text-fw-navy underline">+ Vorschlag</button>
            @error('options')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            @error('options.*')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <label class="block text-sm font-medium text-gray-700">Antworten bis (optional; zwei Tage vorher werden alle ohne Antwort erinnert)<input type="datetime-local" wire:model="deadline" class="mt-1 {{ $input }}"></label>

        <div>
            <p class="text-sm font-medium text-gray-700 mb-1">Für (leer = alle Kameraden mit Kalender)</p>
            <div class="flex flex-wrap gap-3">
                @foreach($groups as $id => $name)<label class="inline-flex items-center gap-1.5 text-sm text-gray-700"><input type="checkbox" value="{{ $id }}" wire:model="groupIds" class="rounded"> {{ $name }}</label>@endforeach
            </div>
            @if($functions->isNotEmpty())
                <div class="mt-1 flex flex-wrap gap-3 border-t border-gray-100 pt-1">
                    @foreach($functions as $id => $name)<label class="inline-flex items-center gap-1.5 text-sm text-gray-700"><input type="checkbox" value="{{ $id }}" wire:model="functionIds" class="rounded"> {{ $name }}</label>@endforeach
                </div>
            @endif
            <select multiple size="4" wire:model="inviteeIds" class="mt-2 {{ $input }}" aria-label="Einzelne Kameraden">@foreach($people as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
            <p class="mt-1 text-xs text-gray-500">Einzelne Kameraden zusätzlich (mehrere mit Strg/Cmd).</p>
        </div>
        <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="notify" class="rounded"> Kameraden per E-Mail einladen</label>
        <button type="submit" class="inline-flex items-center rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white hover:bg-fw-red-dark">Abfrage starten</button>
    </form>
    @include('partials.place-datalist')
</div>

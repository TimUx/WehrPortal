@php($input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red')
<div class="max-w-3xl mx-auto space-y-6">
    <div>
        <a href="{{ route('kalender.index') }}" class="text-sm text-gray-500 hover:text-gray-900">← Kalender</a>
        <h1 class="text-xl font-bold text-gray-900 mt-1">Externe Kalender &amp; Einbindung</h1>
        <p class="text-sm text-gray-500">Kalender anderer Stellen (z. B. Kreisfeuerwehrverband, Gemeinde, Schulferien) per iCal-Adresse einbinden. Die Termine erscheinen für alle im Kalender, sind nur lesbar und werden stündlich aktualisiert.</p>
    </div>

    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif

    <form wire:submit="add" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 space-y-3">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <label class="block text-sm text-gray-700 sm:col-span-2">Name<input type="text" wire:model="name" maxlength="100" placeholder="z. B. Kreisfeuerwehrverband" class="mt-1 {{ $input }}"></label>
            <label class="block text-sm text-gray-700">Terminart
                <select wire:model="type" class="mt-1 {{ $input }}">@foreach($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
            </label>
        </div>
        <label class="block text-sm text-gray-700">iCal-Adresse<input type="text" wire:model="url" maxlength="1000" placeholder="https://… oder webcal://…" class="mt-1 {{ $input }}"></label>
        @foreach(['name', 'url', 'type'] as $field)@error($field)<p class="text-sm text-red-600">{{ $message }}</p>@enderror @endforeach
        <button type="submit" class="inline-flex items-center rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white hover:bg-fw-red-dark">Hinzufügen und abrufen</button>
    </form>

    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100">
        @forelse($sources as $source)
            <div class="p-4 flex flex-wrap items-start justify-between gap-3" wire:key="src-{{ $source->id }}">
                <div class="min-w-0">
                    <p class="font-semibold text-gray-900 {{ $source->is_active ? '' : 'text-gray-400' }}">{{ $source->name }} <span class="font-normal text-gray-500">· {{ $types[$source->type] ?? $source->type }} · {{ $source->events_count }} Termine</span></p>
                    <p class="text-xs text-gray-500 break-all">{{ $source->url }}</p>
                    <p class="text-xs {{ $source->last_error ? 'text-red-600' : 'text-gray-500' }}">
                        {{ $source->last_error ?: ($source->last_synced_at ? 'Zuletzt abgerufen '.$source->last_synced_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') : 'Noch nicht abgerufen') }}
                    </p>
                </div>
                <div class="flex gap-3 text-sm">
                    <button type="button" wire:click="syncNow({{ $source->id }})" class="text-fw-navy underline">Jetzt abrufen</button>
                    <button type="button" wire:click="toggle({{ $source->id }})" class="text-gray-600 underline">{{ $source->is_active ? 'Pausieren' : 'Aktivieren' }}</button>
                    <button type="button" wire:click="remove({{ $source->id }})" wire:confirm="„{{ $source->name }}“ mit allen Terminen entfernen?" class="text-red-600 underline">Entfernen</button>
                </div>
            </div>
        @empty
            <p class="p-4 text-sm text-gray-500">Noch keine externen Kalender.</p>
        @endforelse
    </div>

    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 space-y-3 text-sm text-gray-700" x-data="{ copied: null }">
        <h2 class="text-sm font-semibold text-gray-900">Auf der Website der Wehr einbinden</h2>
        <p>Termine mit dem Häkchen „Öffentlich“ lassen sich auf der eigenen Website zeigen – nur Titel, Zeit, Ort und Beschreibung, keine Namen und keine Rückmeldungen.</p>
        <div>
            <p class="text-xs font-medium text-gray-500 mb-1">HTML-Code für die Website (Liste der nächsten Termine)</p>
            <div class="flex flex-wrap gap-2">
                <input type="text" readonly x-ref="iframe" value='<iframe src="{{ route('kalender.public') }}" style="width:100%;height:600px;border:0" title="Termine"></iframe>' class="flex-1 min-w-0 rounded-lg border border-gray-300 px-3 py-2 font-mono text-xs">
                <button type="button" x-on:click="navigator.clipboard.writeText($refs.iframe.value); copied = 'iframe'" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50" x-text="copied === 'iframe' ? 'Kopiert' : 'Kopieren'"></button>
            </div>
        </div>
        <div>
            <p class="text-xs font-medium text-gray-500 mb-1">iCal-Adresse zum Abonnieren für alle</p>
            <div class="flex flex-wrap gap-2">
                <input type="text" readonly x-ref="ics" value="{{ route('kalender.public.ics') }}" class="flex-1 min-w-0 rounded-lg border border-gray-300 px-3 py-2 font-mono text-xs">
                <button type="button" x-on:click="navigator.clipboard.writeText($refs.ics.value); copied = 'ics'" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50" x-text="copied === 'ics' ? 'Kopiert' : 'Kopieren'"></button>
            </div>
        </div>
        <p><a href="{{ route('kalender.public') }}" target="_blank" rel="noopener" class="text-fw-navy underline">Vorschau öffnen</a></p>
    </div>
</div>

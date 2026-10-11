@php($input = 'mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
<div class="mx-auto max-w-xl space-y-4">
    <div>
        <a href="{{ route('uebungsdienste.index') }}" class="text-sm text-gray-500 hover:text-gray-900">← Übungsdienstplaner</a>
        <h1 class="mt-1 text-xl font-bold text-gray-900">{{ $service ? 'Dienst am '.$service->dateLabel().' ändern' : 'Übungsdienst anlegen' }}</h1>
        <p class="text-sm text-gray-500">{{ $service ? 'Datum, Beginn und Ort. Thema und Verantwortliche ändert der Vorstand direkt im Plan.' : 'Einzeln oder als Serie, z. B. alle zwei Wochen bis Jahresende. Thema und Verantwortliche trägt der Vorstand danach im Plan ein.' }}</p>
    </div>

    @error('plan')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror

    <form wire:submit="save" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 space-y-4">
        @unless($service)
            <div class="grid grid-cols-2 gap-2 rounded-lg bg-gray-100 p-1 text-sm font-semibold">
                <button type="button" wire:click="$set('series', false)" class="rounded-md py-2 {{ $series ? 'text-gray-600' : 'bg-white shadow-sm text-gray-900' }}">Einzelner Dienst</button>
                <button type="button" wire:click="$set('series', true)" class="rounded-md py-2 {{ $series ? 'bg-white shadow-sm text-gray-900' : 'text-gray-600' }}">Serie</button>
            </div>
        @endunless

        <label class="block text-sm font-medium text-gray-700">{{ $series ? 'Erster Dienst' : 'Datum' }}
            <input type="date" wire:model="date" required class="{{ $input }}">
        </label>

        @if($series && ! $service)
            <div class="grid grid-cols-2 gap-3">
                <label class="block text-sm font-medium text-gray-700">Alle … Wochen
                    <input type="number" min="1" max="8" wire:model="every" class="{{ $input }}">
                </label>
                <label class="block text-sm font-medium text-gray-700">Letzter Dienst
                    <input type="date" wire:model="until" required class="{{ $input }}">
                </label>
            </div>
        @endif

        <div class="grid grid-cols-2 gap-3">
            <label class="block text-sm font-medium text-gray-700">Beginn
                <input type="time" wire:model="time" class="{{ $input }}">
            </label>
            <label class="block text-sm font-medium text-gray-700">Ort
                <input type="text" wire:model="location" maxlength="255" class="{{ $input }}">
            </label>
        </div>

        <div class="flex flex-wrap items-center gap-3 pt-2">
            <button type="submit" class="inline-flex items-center rounded-lg bg-fw-red px-4 py-2.5 text-sm font-semibold text-white hover:bg-fw-red-dark">{{ $service ? 'Speichern' : 'Anlegen' }}</button>
            <a href="{{ route('uebungsdienste.index') }}" class="text-sm text-gray-500 underline">Abbrechen</a>
            @if($service)
                <button type="button" wire:click="delete" wire:confirm="Dienst am {{ $service->dateLabel() }} löschen?" class="ml-auto text-sm text-red-600 underline">Dienst löschen</button>
            @endif
        </div>
    </form>
</div>

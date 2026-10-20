@php($input = 'mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
<div class="mx-auto max-w-xl space-y-4">
    <div>
        <a href="{{ route('uebungsdienste.index') }}" class="inline-flex items-center gap-1 rounded-lg py-1.5 pr-2 text-sm font-medium text-fw-navy hover:text-fw-red">← Übungsdienstplaner</a>
        <h1 class="mt-1 text-xl font-bold text-gray-900">{{ $service ? 'Dienst am '.$service->dateLabel().' ändern' : 'Übungsdienst anlegen' }}</h1>
        <p class="text-sm text-gray-500">{{ $service ? 'Datum, Zeit, Treffpunkt und Kleidung. Thema und Verantwortliche pflegt die Wehrführung direkt im Plan.' : 'Einzeln oder als Serie, z. B. alle zwei Wochen bis Jahresende. Thema und Verantwortliche pflegt die Wehrführung danach im Plan.' }}</p>
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
            <label class="block text-sm font-medium text-gray-700">Ende
                <input type="time" wire:model="endTime" class="{{ $input }}">
            </label>
        </div>

        @include('partials.place-dress-fields', ['input' => $input, 'places' => $places, 'dressCodes' => $dressCodes, 'placeId' => $placeId, 'manageUrl' => route('uebungsdienste.places')])

        <div class="space-y-2">
            <p class="text-sm font-medium text-gray-700">Für welche Standorte? <span class="font-normal text-gray-500">(jeweils die Einsatzabteilung)</span></p>
            @if($locationsEnabled && $targetLocations->isNotEmpty())
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-gray-700">
                    @foreach($targetLocations as $id => $name)<label class="flex items-center gap-2"><input type="checkbox" value="{{ $id }}" wire:model="targetLocationIds" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> {{ $name }}</label>@endforeach
                </div>
            @elseif(! $locationsEnabled && $targetGroups->isNotEmpty())
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-gray-700">
                    @foreach($targetGroups as $id => $name)<label class="flex items-center gap-2"><input type="checkbox" value="{{ $id }}" wire:model="targetGroupIds" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> {{ $name }}</label>@endforeach
                </div>
            @else
                <p class="text-sm text-amber-800">Bitte zuerst je Standort die Abteilung „Einsatzabteilung“ anlegen.</p>
            @endif
            @error('targetLocationIds')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        @if($inCalendar)
            <p class="rounded-lg bg-blue-50 px-3 py-2 text-xs text-blue-900">Der Dienst erscheint als „Übungsdienst“ im Kalender der gewählten Einsatzabteilungen – mit Zeit, Treffpunkt und Kleidung, <strong>ohne Thema und Verantwortliche</strong>.</p>
        @endif

        <div class="flex flex-wrap items-center gap-3 pt-2">
            <button type="submit" class="inline-flex items-center rounded-lg bg-fw-red px-4 py-2.5 text-sm font-semibold text-white hover:bg-fw-red-dark">{{ $service ? 'Speichern' : 'Anlegen' }}</button>
            <a href="{{ route('uebungsdienste.index') }}" class="text-sm text-gray-500 underline">Abbrechen</a>
            @if($service)
                <button type="button" wire:click="delete" wire:confirm="Dienst am {{ $service->dateLabel() }} löschen?" class="ml-auto text-sm text-red-600 underline">Dienst löschen</button>
            @endif
        </div>
    </form>
</div>

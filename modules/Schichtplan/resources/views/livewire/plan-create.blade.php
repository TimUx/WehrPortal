<div class="max-w-2xl mx-auto">
    <a href="{{ route('schichtplan.index') }}" class="text-sm text-gray-500 hover:text-gray-900">← Schichtplan</a>
    <h1 class="text-xl font-bold text-gray-900 mt-1 mb-4">Neuer Schichtplan</h1>
    @if($event)
        <p class="mb-4 rounded-lg bg-blue-50 px-3 py-2 text-sm text-blue-900">Für den Kalendertermin „{{ $event->title }}“ ({{ $event->timeLabel() }}). Der Plan erscheint dort mit „Helfer eintragen“.</p>
    @endif

    <form wire:submit="save" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 space-y-4">
        <div>
            <label for="sp-title" class="block text-sm font-medium text-gray-700 mb-1">Wofür? <span class="text-red-600">*</span></label>
            <input id="sp-title" type="text" wire:model="title" maxlength="150" placeholder="z. B. Dorffest – Pommesstand JF" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
            @error('title')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="sp-location" class="block text-sm font-medium text-gray-700 mb-1">Ort</label>
            <input id="sp-location" type="text" wire:model="location" maxlength="150" placeholder="z. B. Festplatz" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <label class="block text-sm font-medium text-gray-700">Datum
                <input type="date" wire:model="date" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
            </label>
            <label class="block text-sm font-medium text-gray-700">Beginn
                <input type="time" wire:model="startTime" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
            </label>
            <label class="block text-sm font-medium text-gray-700">Ende
                <input type="time" wire:model="endTime" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
            </label>
        </div>
        <p class="text-xs text-gray-500">Liegt das Ende vor dem Beginn, geht der Plan über Mitternacht.</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <label class="block text-sm font-medium text-gray-700">Schichtlänge
                <select wire:model="shiftMinutes" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
                    @foreach([30 => '30 Minuten', 45 => '45 Minuten', 60 => '1 Stunde', 90 => '1,5 Stunden', 120 => '2 Stunden', 180 => '3 Stunden'] as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm font-medium text-gray-700">Höchstens Schichten am Stück
                <input type="number" min="1" max="24" wire:model="maxConsecutive" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
            </label>
        </div>
        @error('shiftMinutes')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <label class="block text-sm font-medium text-gray-700">Abteilung (optional)
                <select wire:model="groupId" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
                    <option value="">–</option>
                    @foreach($groups as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
            </label>
            <label class="block text-sm font-medium text-gray-700">Stationen aus Vorlage (optional)
                <select wire:model="templateId" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
                    <option value="">–</option>
                    @foreach($templates as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
            </label>
        </div>
        @include('schichtplan::partials.signup-settings')
        <button type="submit" class="inline-flex items-center rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white hover:bg-fw-red-dark">Plan anlegen</button>
    </form>
</div>

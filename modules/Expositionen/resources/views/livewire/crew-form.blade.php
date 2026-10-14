<div class="mx-auto max-w-2xl space-y-4">
    <div>
        <a href="{{ route('einsatzbericht.show', $report) }}" class="inline-flex items-center gap-1 rounded-lg py-1.5 pr-2 text-sm font-medium text-fw-navy hover:text-fw-red">← Einsatz {{ $report->number }}</a>
        <h1 class="text-xl font-bold text-gray-900">Gefahrstoff-Exposition für die Besatzung</h1>
        <p class="text-sm text-gray-500">Die Angaben gehen als Vorschlag an jede ausgewählte Einsatzkraft; sie ergänzt (z. B. Dauer, eigene Schutzausrüstung) und bestätigt. Wer im Einsatzbericht „unter Atemschutz“ steht, ist vorausgewählt und hat umluftunabhängigen Atemschutz schon eingetragen.</p>
    </div>
    @error('exposure')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror
    <form wire:submit="save" class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <fieldset><legend class="text-sm font-semibold text-gray-900">Einsatzkräfte</legend>
            <div class="mt-1 grid grid-cols-1 gap-1 sm:grid-cols-2">
                @foreach($crew as $c)
                    <label class="flex items-center gap-2 text-sm {{ in_array($c->user_id, $known, true) ? 'text-gray-400' : 'text-gray-700' }}">
                        <input type="checkbox" wire:model="crewIds" value="{{ $c->user_id }}" @disabled(in_array($c->user_id, $known, true)) class="rounded">
                        {{ $c->user?->name }}{{ $c->breathing_apparatus ? ' · AS' : '' }}{{ in_array($c->user_id, $known, true) ? ' · schon erfasst' : '' }}</label>
                @endforeach
            </div>
            @error('crew')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        </fieldset>
        @include('expositionen::partials.fields')
        <button type="submit" class="rounded-lg bg-fw-navy px-4 py-2 text-sm font-semibold text-white">Vorschlagen</button>
    </form>
</div>

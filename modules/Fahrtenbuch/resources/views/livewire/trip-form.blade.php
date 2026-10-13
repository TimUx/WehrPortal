@php($input = 'mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
@php($error = 'text-sm text-red-600')
@php($editStart = in_array($mode, ['start', 'record', 'correct'], true))
@php($editEnd = in_array($mode, ['end', 'record', 'correct'], true))
<form wire:submit="save" class="mx-auto max-w-2xl space-y-4">
    <h1 class="text-xl font-bold text-gray-900">{{ ['start' => 'Fahrt beginnen', 'end' => 'Fahrt beenden', 'record' => 'Fahrt nachtragen', 'correct' => 'Fahrt korrigieren', 'view' => 'Fahrt'][$mode] }}</h1>
    @error('trip')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror
    @if($trip?->voided_at)<p class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-700">Storniert: {{ $trip->void_reason }}</p>@endif

    @if($mode === 'end' || $mode === 'view')
        <section class="rounded-2xl border border-gray-100 bg-white p-4 text-sm shadow-sm">
            <p class="font-semibold text-gray-900">{{ $trip->vehicle->name }} · {{ $trip->driver->name }}</p>
            <p class="text-gray-600">Los {{ $trip->started_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }} Uhr in {{ $trip->origin }} bei {{ number_format($trip->start_km, 0, ',', '.') }} km · {{ $trip->purposeLabel() }}{{ $trip->purpose_note ? ' – '.$trip->purpose_note : '' }}{{ $trip->report ? ' · Einsatz '.$trip->report->number : '' }}</p>
            @if($trip->ended_at)<p class="text-gray-600">Zurück {{ $trip->ended_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }} Uhr in {{ $trip->destination }} bei {{ number_format($trip->end_km, 0, ',', '.') }} km ({{ number_format($trip->distance(), 0, ',', '.') }} km)</p>@endif
            @if($mode === 'view' && $canManage)<a href="{{ route('fahrtenbuch.trip', ['fahrt' => $trip->id, 'korrigieren' => 1]) }}" class="mt-1 inline-block text-xs text-fw-navy underline">korrigieren</a>@endif
        </section>
    @endif

    @if($editStart)
        <section class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <label class="block text-sm text-gray-700">Fahrzeug
                    <select wire:model.live="vehicleId" @disabled($mode === 'correct') class="{{ $input }}">
                        <option value="">– bitte wählen –</option>
                        @foreach($vehicles as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach
                    </select>
                </label>
                <label class="block text-sm text-gray-700">Fahrer
                    <select wire:model.live="driverId" class="{{ $input }}">@foreach($drivers as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>
                </label>
            </div>
            @error('vehicle_id')<p class="{{ $error }}">{{ $message }}</p>@enderror
            @error('driver_id')<p class="{{ $error }}">{{ $message }}</p>@enderror
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <label class="block text-sm text-gray-700">Losgefahren<input type="datetime-local" wire:model="startedAt" class="{{ $input }}"></label>
                <label class="block text-sm text-gray-700">Kilometerstand beim Losfahren<input type="number" inputmode="numeric" wire:model="startKm" class="{{ $input }} "></label>
            </div>
            @if($lastKm !== null)<p class="text-xs text-gray-500">Zuletzt bekannt: {{ number_format($lastKm, 0, ',', '.') }} km</p>@endif
            @if($licenseWarning)<p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">⚠ {{ $licenseWarning }}</p>@endif
            @error('started_at')<p class="{{ $error }}">{{ $message }}</p>@enderror
            @error('start_km')<p class="{{ $error }}">{{ $message }}</p>@enderror
            <label class="block text-sm text-gray-700">Start<input type="text" wire:model="origin" maxlength="200" class="{{ $input }}"></label>
            @error('origin')<p class="{{ $error }}">{{ $message }}</p>@enderror
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <label class="block text-sm text-gray-700">Zweck
                    <select wire:model.live="purpose" class="{{ $input }}"><option value="">– bitte wählen –</option>@foreach(\Modules\Fahrtenbuch\src\Models\Trip::PURPOSES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                </label>
                @if(in_array($purpose, ['einsatz', 'uebung'], true) && $reports->isNotEmpty())
                    <label class="block text-sm text-gray-700">Einsatz (Einsatzbericht)
                        <select wire:model="reportId" class="{{ $input }}"><option value="">–</option>@foreach($reports as $r)<option value="{{ $r->id }}">{{ $r->number }} · {{ $r->keyword }} · {{ $r->alarmed_at?->setTimezone('Europe/Berlin')->format('d.m. H:i') }}</option>@endforeach</select>
                    </label>
                @endif
            </div>
            @error('purpose')<p class="{{ $error }}">{{ $message }}</p>@enderror
            <label class="block text-sm text-gray-700">Anlass{{ in_array($purpose, ['dienstfahrt', 'sonstiges'], true) ? '' : ' (optional)' }}<input type="text" wire:model="purposeNote" maxlength="300" placeholder="z. B. Schläuche zur Prüfung bringen" class="{{ $input }}"></label>
            @error('purpose_note')<p class="{{ $error }}">{{ $message }}</p>@enderror
            <label class="block text-sm text-gray-700">Mitfahrer (Anzahl, optional)<input type="number" min="0" max="99" wire:model="passengers" class="{{ $input }} sm:w-32"></label>
            @foreach($hints as $hint)<p class="text-xs text-blue-800">ℹ {{ $hint }}</p>@endforeach
        </section>
    @endif

    @if($editEnd)
        <section class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <label class="block text-sm text-gray-700">Zurück<input type="datetime-local" wire:model="endedAt" class="{{ $input }}"></label>
                <label class="block text-sm text-gray-700">Kilometerstand bei der Rückkehr<input type="number" inputmode="numeric" wire:model="endKm" class="{{ $input }} "></label>
            </div>
            @error('ended_at')<p class="{{ $error }}">{{ $message }}</p>@enderror
            @error('end_km')<p class="{{ $error }}">{{ $message }}</p>@enderror
            <label class="block text-sm text-gray-700">Ziel<input type="text" wire:model="destination" maxlength="200" placeholder="z. B. Atemschutzwerkstatt Kreisstadt" class="{{ $input }}"></label>
            @error('destination')<p class="{{ $error }}">{{ $message }}</p>@enderror
        </section>
    @endif

    @if($mode !== 'view')
        <label class="block text-sm text-gray-700">Bemerkung (optional)<textarea wire:model="notes" rows="2" maxlength="2000" class="{{ $input }}"></textarea></label>
    @endif

    @if($mode === 'correct')
        <section class="space-y-2 rounded-2xl border border-amber-200 bg-amber-50 p-4">
            <label class="block text-sm text-amber-900">Grund der Korrektur bzw. Stornierung<input type="text" wire:model="reason" maxlength="300" class="{{ $input }}"></label>
            @error('reason')<p class="{{ $error }}">{{ $message }}</p>@enderror
            <p class="text-xs text-amber-900">Die alten Werte bleiben mit Name und Grund in der Fahrt gespeichert.</p>
            @unless($trip->voided_at)<button type="button" wire:click="void" wire:confirm="Fahrt stornieren? Sie bleibt durchgestrichen im Fahrtenbuch." class="text-sm text-red-700 underline">Fahrt stornieren</button>@endunless
        </section>
    @endif

    @if($trip && $trip->revisions->isNotEmpty())
        <section class="space-y-1 text-xs text-gray-600">
            <p class="font-semibold text-gray-700">Korrekturen</p>
            @foreach($trip->revisions as $rev)
                <p>{{ $rev->created_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }} · {{ $rev->user?->name }} · {{ $rev->reason }}:
                    {{ collect($rev->after)->filter(fn ($v, $k) => ($rev->before[$k] ?? null) != $v)->map(fn ($v, $k) => $k.' '.json_encode($rev->before[$k] ?? null, JSON_UNESCAPED_UNICODE).' → '.json_encode($v, JSON_UNESCAPED_UNICODE))->implode(', ') }}</p>
            @endforeach
        </section>
    @endif

    <div class="flex items-center gap-3">
        @if($mode !== 'view')
            <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-fw-red px-5 py-2.5 text-sm font-semibold text-white hover:bg-fw-red-dark disabled:opacity-50">
                {{ ['start' => 'Losfahren', 'end' => 'Fahrt beenden', 'record' => 'Nachtragen', 'correct' => 'Korrektur speichern'][$mode] }}
            </button>
        @endif
        <a href="{{ $trip ? route('fahrtenbuch.vehicle', $trip->vehicle_id) : route('fahrtenbuch.index') }}" class="text-sm text-gray-500 underline">{{ $mode === 'view' ? 'Zurück' : 'Abbrechen' }}</a>
    </div>
</form>

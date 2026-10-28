@php($input = 'mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
<div class="space-y-4">
    <div>
        <h1 class="text-xl font-bold text-gray-900">Atemschutzüberwachung</h1>
        <p class="text-sm text-gray-500">Trupps im Einsatz mit Zeit, Drücken, Rückzugsdruck und Warnungen nach FwDV 7 – jeder Trupp wird automatisch dokumentiert.</p>
    </div>

    <div class="grid grid-cols-3 gap-3">
        <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm"><p class="text-xs text-gray-500">Einsatzbereit</p><p class="text-2xl font-bold text-green-700">{{ $counts['ready'] }}</p></div>
        <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm"><p class="text-xs text-gray-500">Frist läuft ab</p><p class="text-2xl font-bold text-amber-700">{{ $counts['soon'] }}</p></div>
        <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm"><p class="text-xs text-gray-500">Nicht einsatzbereit</p><p class="text-2xl font-bold text-red-700">{{ $counts['not_ready'] }}</p></div>
    </div>

    @foreach($running as $op)
        <a href="{{ $canMonitor ? route('atemschutz.board', $op) : '#' }}" class="flex items-center justify-between gap-3 rounded-2xl border-2 border-fw-red bg-white p-4 shadow-sm">
            <span><span class="block font-semibold text-gray-900">{{ $op->title }}</span><span class="text-xs text-gray-500">läuft seit {{ $op->started_at->setTimezone('Europe/Berlin')->format('H:i') }} · {{ $op->active_count }} Trupp{{ $op->active_count === 1 ? '' : 's' }} unter Atemschutz {{ config('locations.enabled') && $op->ext_location_id ? '· '.$op->location?->name : '' }}</span></span>
            @if($canMonitor)<span class="rounded-lg bg-fw-red px-3 py-1.5 text-sm font-semibold text-white">Öffnen</span>@endif
        </a>
    @endforeach

    @if($canMonitor)
        <form wire:submit="start" class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <p class="text-sm font-semibold text-gray-900">Überwachung starten</p>
            <div class="flex gap-2">
                @foreach(\Modules\Atemschutz\src\Models\Operation::KINDS as $value => $label)
                    <label class="flex flex-1 items-center justify-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm has-[:checked]:border-fw-red has-[:checked]:bg-red-50"><input type="radio" wire:model="kind" value="{{ $value }}"> {{ $label }}</label>
                @endforeach
            </div>
            <label class="block text-sm text-gray-700">Bezeichnung (optional)<input type="text" wire:model="title" maxlength="150" placeholder="z. B. Wohnungsbrand Hauptstraße" class="{{ $input }}"></label>
            @if($reports->isNotEmpty())
                <label class="block text-sm text-gray-700">Zum Einsatzbericht (optional)
                    <select wire:model="reportId" class="{{ $input }}">
                        <option value="">– keiner –</option>
                        @foreach($reports as $r)<option value="{{ $r->id }}">{{ $r->number }} · {{ $r->keyword }} · {{ $r->alarmed_at?->setTimezone('Europe/Berlin')->format('d.m. H:i') }}</option>@endforeach
                    </select>
                </label>
            @endif
            @if(config('locations.enabled') && $reportId === '')
                <label class="block text-sm text-gray-700">Standort der Überwachung
                    <select wire:model="locationId" class="{{ $input }}" required>
                        <option value="">– Standort auswählen –</option>
                        @foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach
                    </select>
                </label>
                @if($locations->isEmpty())<p class="text-sm text-red-600">Dir ist kein aktiver Standort für eine Atemschutzüberwachung zugeordnet.</p>@endif
            @elseif($reportId !== '' && $reportLocationId !== null)
                <p class="text-sm text-gray-600">Standort wird aus dem Einsatzbericht übernommen.</p>
            @elseif($reportId !== '')
                <p class="text-sm text-red-600">Der verknüpfte Einsatzbericht hat keinen zugeordneten Standort.</p>
            @endif
            @foreach(['kind', 'report_id', 'location_id', 'operation'] as $field)@error($field)<p class="text-sm text-red-600">{{ $message }}</p>@enderror @endforeach
            <button type="submit" class="rounded-lg bg-fw-red px-5 py-2.5 text-sm font-semibold text-white hover:bg-fw-red-dark">Starten</button>
        </form>

        <section class="space-y-2">
            <p class="text-sm font-semibold text-gray-900">Jetzt verfügbar ({{ $available->count() }} einsatzbereit{{ $busyCount ? ', '.$busyCount.' unter Atemschutz' : '' }})</p>
            <div class="flex flex-wrap gap-2">
                @forelse($available as $s)
                    <span class="rounded-full border px-3 py-1 text-sm {{ $s['soon'] ? 'border-amber-300 bg-amber-50 text-amber-900' : 'border-green-300 bg-green-50 text-green-900' }}">{{ $s['wearer']->user->name }}</span>
                @empty
                    <p class="text-sm text-gray-500">Niemand einsatzbereit im Pool.</p>
                @endforelse
            </div>
        </section>

        @if($recent->isNotEmpty())
            <section class="space-y-2">
                <p class="text-sm font-semibold text-gray-900">Letzte Überwachungen</p>
                <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
                    @foreach($recent as $op)
                        <div class="flex items-center justify-between gap-3 p-3 text-sm" wire:key="r-{{ $op->id }}">
                            <a href="{{ route('atemschutz.board', $op) }}" class="min-w-0"><span class="block text-gray-900">{{ $op->title }}</span><span class="text-xs text-gray-500">{{ $op->started_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }} · {{ $op->teams_count }} Trupps {{ config('locations.enabled') && $op->ext_location_id ? '· '.$op->location?->name : '' }}</span></a>
                            <a href="{{ route('atemschutz.pdf', $op) }}" target="_blank" class="shrink-0 text-xs text-fw-navy underline">PDF</a>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    @else
        <p class="text-sm text-gray-600">Deinen eigenen Stand (G26.3, Unterweisung, Belastungsübung) findest du unter <a href="{{ route('atemschutz.personnel-file') }}" class="underline">Mein Status</a>.</p>
    @endif
</div>

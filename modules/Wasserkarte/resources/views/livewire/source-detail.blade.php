@php($input = 'mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red')
@php($error = 'text-sm text-red-600')
@php($card = 'space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm')
<div class="mx-auto max-w-3xl space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm text-gray-500"><a href="{{ route('wasserkarte.index') }}" class="underline">Wasserkarte</a> · {{ $source->kindLabel() }}</p>
            <h1 class="text-xl font-bold text-gray-900">{{ $source->number ?: $source->kindLabel() }}{{ $source->location ? ' · '.$source->location : '' }}</h1>
        </div>
        <div class="text-right">
            <span class="inline-block rounded-full px-2.5 py-1 text-xs font-semibold text-white" style="background-color: {{ \Modules\Wasserkarte\src\Models\Source::COLORS[$source->status] }}">{{ $source->statusLabel() }}</span>
            @if($source->status_note)<p class="text-xs text-gray-600">{{ $source->status_note }}</p>@endif
        </div>
    </div>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @error('wasserkarte')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror

    <section class="{{ $card }}">
        <dl class="grid grid-cols-1 gap-x-4 gap-y-1 text-sm sm:grid-cols-[11rem_1fr]">
            <dt class="text-gray-500">Leistung</dt><dd>{{ $source->performance() ?: '–' }}</dd>
            @if($source->operator)<dt class="text-gray-500">Betreiber</dt><dd>{{ $source->operator }}</dd>@endif
            <dt class="text-gray-500">Letzte Prüfung</dt><dd>{{ $source->last_inspected_on?->format('d.m.Y') ?? 'noch keine' }}</dd>
            @if($source->next_inspection_on)<dt class="text-gray-500">Nächste Prüfung</dt><dd class="{{ $source->inspectionOverdue() ? 'font-semibold text-amber-800' : '' }}">{{ $source->next_inspection_on->format('d.m.Y') }}</dd>@endif
            <dt class="text-gray-500">Position</dt><dd>{{ number_format($source->latitude, 6, ',', '') }}, {{ number_format($source->longitude, 6, ',', '') }} · <a href="{{ $source->navigationUrl() }}" target="_blank" rel="noopener" class="underline">Navigation</a></dd>
            @if($source->osm_id)<dt class="text-gray-500">Herkunft</dt><dd>OpenStreetMap · <a href="https://www.openstreetmap.org/node/{{ $source->osm_id }}" target="_blank" rel="noopener" class="underline">Node {{ $source->osm_id }}</a> <span class="text-xs text-gray-500">(© OpenStreetMap-Mitwirkende, ODbL)</span></dd>@endif
            @if($source->notes)<dt class="text-gray-500">Notizen</dt><dd class="whitespace-pre-line">{{ $source->notes }}</dd>@endif
        </dl>
        @if($canManage)<a href="{{ route('wasserkarte.edit', $source) }}" class="text-xs text-fw-navy underline">Bearbeiten</a>@endif
    </section>

    <section class="{{ $card }}">
        <p class="text-sm font-semibold text-gray-900">Mangel melden</p>
        <p class="text-xs text-gray-500">Zugeparkt, Deckel festgerostet, Schild fehlt, kein Wasser? Die Quelle steht dann sofort auf „defekt“ und der Hydrantenwart wird informiert.</p>
        <textarea wire:model="defectDescription" rows="2" maxlength="1000" class="{{ $input }}" placeholder="Was stimmt nicht?"></textarea>
        <input type="file" wire:model="defectPhoto" accept="image/*" class="text-sm">
        @error('defect_description')<p class="{{ $error }}">{{ $message }}</p>@enderror
        @error('defectPhoto')<p class="{{ $error }}">{{ $message }}</p>@enderror
        <button type="button" wire:click="reportDefect" wire:loading.attr="disabled" class="rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white">Mangel melden</button>
        @foreach($defects as $d)
            <div class="border-t border-gray-100 pt-2 text-sm" wire:key="d-{{ $d->id }}">
                <p class="{{ $d->isOpen() ? 'text-red-800' : 'text-gray-500' }}">{{ $d->created_at->setTimezone('Europe/Berlin')->format('d.m.Y') }} · {{ $d->reporter?->name ?? '–' }}: {{ $d->description }}</p>
                @if($d->photo)<a href="{{ route('wasserkarte.defect.photo', $d) }}" target="_blank" class="text-xs text-fw-navy underline">Foto</a>@endif
                @if($d->isOpen())
                    @if($canManage)
                        <div class="mt-1 flex flex-wrap gap-2"><input type="text" wire:model="resolution" maxlength="500" placeholder="Was wurde gemacht?" class="min-w-0 flex-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm"><button type="button" wire:click="resolveDefect({{ $d->id }})" class="rounded-lg bg-green-600 px-3 py-1.5 text-sm font-semibold text-white">Behoben</button></div>
                    @endif
                @else
                    <p class="text-xs text-green-700">Behoben {{ $d->resolved_at->setTimezone('Europe/Berlin')->format('d.m.Y') }} ({{ $d->resolver?->name }}){{ $d->resolution ? ': '.$d->resolution : '' }}</p>
                @endif
            </div>
        @endforeach
    </section>

    @if($canManage)
        <section class="{{ $card }}">
            <p class="text-sm font-semibold text-gray-900">Status</p>
            <div class="flex flex-wrap items-end gap-2">
                <select wire:model="status" class="rounded-lg border border-gray-300 px-3 py-2 text-sm">@foreach(\Modules\Wasserkarte\src\Models\Source::STATUSES as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
                <input type="text" wire:model="statusNote" maxlength="300" placeholder="Grund, z. B. Baustelle bis 30.06." class="min-w-0 flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm">
                <button type="button" wire:click="saveStatus" class="rounded-lg bg-fw-navy px-3 py-2 text-sm font-semibold text-white">Speichern</button>
            </div>
            @error('status_note')<p class="{{ $error }}">{{ $message }}</p>@enderror
        </section>

        <section class="{{ $card }}">
            <p class="text-sm font-semibold text-gray-900">Prüfung eintragen</p>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                <label class="block text-xs text-gray-600">Datum<input type="date" wire:model="inspection.inspected_on" class="{{ $input }}"></label>
                <label class="block text-xs text-gray-600">Ergebnis<select wire:model="inspection.result" class="{{ $input }}">@foreach(\Modules\Wasserkarte\src\Models\Inspection::RESULTS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select></label>
                <label class="block text-xs text-gray-600">Durchfluss (l/min)<input type="number" wire:model="inspection.flow_lpm" class="{{ $input }}"></label>
                <label class="block text-xs text-gray-600">Ruhedruck (bar)<input type="text" inputmode="decimal" wire:model="inspection.static_pressure_bar" class="{{ $input }}"></label>
                <label class="block text-xs text-gray-600">Fließdruck (bar)<input type="text" inputmode="decimal" wire:model="inspection.flow_pressure_bar" class="{{ $input }}"></label>
            </div>
            <input type="text" wire:model="inspection.notes" maxlength="1000" placeholder="Bemerkung (bei Mangel Pflicht)" class="{{ $input }}">
            @foreach(['inspected_on', 'result', 'inspection_notes'] as $field)@error($field)<p class="{{ $error }}">{{ $message }}</p>@enderror @endforeach
            <button type="button" wire:click="saveInspection" class="rounded-lg bg-fw-navy px-3 py-2 text-sm font-semibold text-white">Prüfung speichern</button>
        </section>
    @endif

    @if($inspections->isNotEmpty())
        <section class="{{ $card }}">
            <p class="text-sm font-semibold text-gray-900">Prüfungen</p>
            @foreach($inspections as $i)
                <p class="text-sm">{{ $i->inspected_on->format('d.m.Y') }} · <span class="{{ $i->result === 'defect' ? 'text-red-700' : 'text-green-700' }}">{{ \Modules\Wasserkarte\src\Models\Inspection::RESULTS[$i->result] }}</span>
                    {{ collect([$i->flow_lpm ? $i->flow_lpm.' l/min' : null, $i->static_pressure_bar ? 'Ruhedruck '.str_replace('.', ',', (string) $i->static_pressure_bar).' bar' : null, $i->flow_pressure_bar ? 'Fließdruck '.str_replace('.', ',', (string) $i->flow_pressure_bar).' bar' : null])->filter()->implode(' · ') }}
                    <span class="text-xs text-gray-500">{{ $i->notes ? '· '.$i->notes : '' }} · {{ $i->user?->name }}</span></p>
            @endforeach
        </section>
    @endif

    @if($events->isNotEmpty())
        <section>
            <p class="mb-1 text-sm font-semibold text-gray-700">Statusverlauf</p>
            <ul class="space-y-0.5 text-xs text-gray-600">
                @foreach($events as $e)<li>{{ $e->created_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }} · {{ \Modules\Wasserkarte\src\Models\Source::STATUSES[$e->status] ?? $e->status }}{{ $e->note ? ': '.$e->note : '' }} · {{ $e->user?->name ?? 'System' }}</li>@endforeach
            </ul>
        </section>
    @endif
</div>

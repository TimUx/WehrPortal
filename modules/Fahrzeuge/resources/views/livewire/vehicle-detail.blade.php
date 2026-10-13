@php($input = 'mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red')
@php($error = 'text-sm text-red-600')
@php($card = 'space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm')
@php($berlin = fn ($t) => \Illuminate\Support\Carbon::parse($t)->setTimezone('Europe/Berlin'))
<div class="mx-auto max-w-4xl space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex items-center gap-3">
            @if($profile->photo_updated_at)<img src="{{ route('fahrzeuge.photo', ['vehicle' => $vehicle, 'v' => $profile->photo_updated_at->timestamp]) }}" alt="" class="h-20 w-28 rounded-lg object-cover">@endif
            <div>
                <p class="text-sm text-gray-500"><a href="{{ route('fahrzeuge.index') }}" class="underline">Fahrzeuge</a> · {{ $profile->categoryLabel() ?? 'Fahrzeug' }}</p>
                <h1 class="text-xl font-bold text-gray-900">{{ $vehicle->name }}</h1>
                <p class="text-sm text-gray-600">{{ collect([$profile->call_sign, $profile->license_plate])->filter()->implode(' · ') }}</p>
            </div>
        </div>
        <div class="text-right">
            @include('fahrzeuge::partials.state', ['state' => $state])
            @if($state['detail'])<p class="text-xs text-gray-600">{{ $state['detail'] }}</p>@endif
            @if($canManage)<a href="{{ route('fahrzeuge.edit', $vehicle) }}" class="mt-1 block text-xs text-fw-navy underline">Stammdaten bearbeiten</a>@endif
        </div>
    </div>

    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @error('fahrzeuge')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror
    @if($assignment)
        <p class="rounded-lg bg-blue-50 px-3 py-2 text-sm text-blue-900">Im Einsatz: {{ $assignment['keyword'] ?: 'Einsatz' }} ({{ $assignment['number'] }}), {{ $assignment['crew'] }} Personen{{ $assignment['departed_at'] ? ', ausgerückt '.$assignment['departed_at']->setTimezone('Europe/Berlin')->format('H:i') : '' }}
            @if($canSeeReports) · <a href="{{ route('einsatzbericht.show', $assignment['report_id']) }}" class="underline">Einsatzbericht</a>@endif</p>
    @endif

    <section class="{{ $card }}">
        <dl class="grid grid-cols-1 gap-x-4 gap-y-1.5 text-sm sm:grid-cols-[11rem_1fr_11rem_1fr]">
            @foreach(['Typ / Norm' => $profile->vehicle_type, 'Fahrgestell' => $profile->chassis, 'Aufbau' => $profile->body_builder, 'Baujahr' => $profile->year_built,
                'Erstzulassung' => $profile->first_registration?->format('d.m.Y'), 'FIN' => $profile->vin,
                'Zul. Gesamtmasse' => $profile->gross_weight_kg ? number_format($profile->gross_weight_kg, 0, ',', '.').' kg' : null, 'Besatzung' => $profile->crew,
                'Führerschein' => $profile->license_class, 'Löschwasser' => $profile->water_liters ? number_format($profile->water_liters, 0, ',', '.').' l' : null,
                'Schaummittel' => $profile->foam_liters ? number_format($profile->foam_liters, 0, ',', '.').' l' : null, 'Pumpe' => $profile->pump,
                'Kilometerstand' => $profile->mileage_km !== null ? number_format($profile->mileage_km, 0, ',', '.').' km'.($profile->mileage_on ? ' ('.$profile->mileage_on->format('d.m.Y').')' : '') : null,
                'Betriebsstunden' => $profile->operating_hours !== null ? str_replace('.', ',', (string) $profile->operating_hours).' h' : null] as $label => $value)
                @if(filled($value))<dt class="text-gray-500">{{ $label }}</dt><dd>{{ $value }}</dd>@endif
            @endforeach
        </dl>
        @if($profile->features)
            <div class="flex flex-wrap gap-1.5 pt-1">@foreach($profile->features as $feature)<span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs text-gray-700">{{ $feature }}</span>@endforeach</div>
        @endif
        @if($profile->notes)<p class="whitespace-pre-line pt-1 text-sm text-gray-700">{{ $profile->notes }}</p>@endif
        @if(! $profile->exists)<p class="text-sm text-gray-500">Noch keine Stammdaten.@if($canManage) <a href="{{ route('fahrzeuge.edit', $vehicle) }}" class="underline">Jetzt erfassen</a>@endif</p>@endif
    </section>

    @if($canManage)
        <section class="{{ $card }}">
            <p class="text-sm font-semibold text-gray-900">Status</p>
            @if($vehicle->is_active)
                <div class="flex flex-wrap items-end gap-2">
                    <select wire:model="status" class="rounded-lg border border-gray-300 px-3 py-2 text-sm" aria-label="Status">
                        @foreach(\Modules\Fahrzeuge\src\Models\Profile::STATUSES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                    <input type="text" wire:model="statusNote" maxlength="300" placeholder="Was ist los? (z. B. Blaulicht hinten links defekt)" class="min-w-0 flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm">
                    <button type="button" wire:click="saveStatus" class="rounded-lg bg-fw-navy px-3 py-2 text-sm font-semibold text-white">Speichern</button>
                </div>
                @error('status_note')<p class="{{ $error }}">{{ $message }}</p>@enderror
                <button type="button" wire:click="decommission" wire:confirm="{{ $vehicle->name }} außer Dienst stellen? Es ist dann nicht mehr buchbar." class="text-xs text-red-600 underline">Außer Dienst stellen</button>
            @else
                <p class="text-sm text-gray-600">Außer Dienst – nicht buchbar, nicht im Einsatzbericht wählbar.</p>
                <button type="button" wire:click="recommission" class="text-sm text-fw-navy underline">Wieder in Dienst stellen</button>
            @endif
        </section>
    @endif

    <section class="{{ $card }}">
        <p class="text-sm font-semibold text-gray-900">Werkstatt & Ausfallzeiten</p>
        <p class="text-xs text-gray-500">In diesen Zeiten ist das Fahrzeug in der Fahrzeugbuchung nicht buchbar.</p>
        @forelse($downtimes as $d)
            <div class="flex items-center justify-between gap-3 text-sm" wire:key="dt-{{ $d->id }}">
                <span class="{{ $d->isCurrent() ? 'font-semibold text-red-700' : '' }}">{{ $d->periodLabel() }}: {{ $d->reason }}</span>
                @if($canManage)<button type="button" wire:click="endDowntime({{ $d->id }})" class="shrink-0 text-xs text-fw-navy underline">{{ $d->isCurrent() ? 'Wieder da' : 'entfernen' }}</button>@endif
            </div>
        @empty
            <p class="text-sm text-gray-500">Keine.</p>
        @endforelse
        @if($canManage && $vehicle->is_active)
            <div class="grid grid-cols-1 gap-2 border-t border-gray-100 pt-2 sm:grid-cols-[1fr_1fr_2fr_auto] sm:items-end">
                <label class="block text-xs text-gray-600">Von<input type="datetime-local" wire:model="downtime.starts_at" class="{{ $input }}"></label>
                <label class="block text-xs text-gray-600">Bis (leer = bis auf Weiteres)<input type="datetime-local" wire:model="downtime.ends_at" class="{{ $input }}"></label>
                <label class="block text-xs text-gray-600">Grund<input type="text" wire:model="downtime.reason" maxlength="200" placeholder="z. B. Werkstatt: Inspektion" class="{{ $input }}"></label>
                <button type="button" wire:click="addDowntime" class="rounded-lg bg-fw-navy px-3 py-2 text-sm font-semibold text-white">Eintragen</button>
            </div>
            @foreach(['downtime_starts_at', 'downtime_ends_at', 'downtime_reason'] as $field)@error($field)<p class="{{ $error }}">{{ $message }}</p>@enderror @endforeach
        @endif
    </section>

    <section class="{{ $card }}">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm font-semibold text-gray-900">Fristen & Wartungsintervalle</p>
            @if($canManage && $editingDeadline === null)
                <div class="flex flex-wrap gap-2 text-xs">
                    <select x-data x-on:change="$wire.editDeadline(null, $event.target.value); $event.target.value = ''" class="rounded-lg border border-gray-300 px-2 py-1 text-xs" aria-label="Frist hinzufügen">
                        <option value="">Frist hinzufügen …</option>
                        @foreach(array_keys(\Modules\Fahrzeuge\src\Models\Deadline::PRESETS) as $preset)<option value="{{ $preset }}">{{ $preset }}</option>@endforeach
                        <option value=" ">Eigene Frist …</option>
                    </select>
                </div>
            @endif
        </div>
        @if($editingDeadline !== null)
            <div class="grid grid-cols-1 gap-2 rounded-lg bg-gray-50 p-3 sm:grid-cols-2">
                <label class="block text-xs text-gray-600">Was?<input type="text" wire:model.blur="deadline.title" maxlength="150" class="{{ $input }}"></label>
                <label class="block text-xs text-gray-600">Fällig am<input type="date" wire:model="deadline.due_on" class="{{ $input }}"></label>
                <label class="block text-xs text-gray-600">Wiederholung alle … Monate (leer = einmalig)<input type="number" min="1" max="240" wire:model="deadline.interval_months" class="{{ $input }}"></label>
                <label class="block text-xs text-gray-600">Vorwarnung (Tage)<input type="number" min="0" max="365" wire:model="deadline.lead_days" class="{{ $input }}"></label>
                <label class="block text-xs text-gray-600 sm:col-span-2">Notiz<input type="text" wire:model="deadline.notes" maxlength="500" class="{{ $input }}"></label>
                @foreach(['deadline_title', 'deadline_due_on', 'deadline_interval', 'deadline_lead'] as $field)@error($field)<p class="{{ $error }} sm:col-span-2">{{ $message }}</p>@enderror @endforeach
                <div class="flex gap-3 sm:col-span-2"><button type="button" wire:click="saveDeadline" class="rounded-lg bg-fw-red px-3 py-1.5 text-sm font-semibold text-white">Speichern</button><button type="button" wire:click="cancel" class="text-sm text-gray-500 underline">Abbrechen</button></div>
            </div>
        @endif
        @forelse($deadlines as $d)
            <div class="flex flex-wrap items-center justify-between gap-2 text-sm" wire:key="dl-{{ $d->id }}">
                <span>{{ $d->title }}<span class="block text-xs {{ $d->isOverdue() ? 'font-semibold text-red-700' : ($d->isDue() ? 'text-amber-800' : 'text-gray-500') }}">{{ $d->isOverdue() ? 'überfällig seit' : 'fällig' }} {{ $d->due_on->format('d.m.Y') }}{{ $d->interval_months ? ' · alle '.$d->interval_months.' Monate' : '' }}</span></span>
                @if($canManage)
                    <span class="flex gap-3 text-xs">
                        <button type="button" wire:click="startRecord({{ $d->id }})" class="font-semibold text-fw-navy underline">erledigt eintragen</button>
                        <button type="button" wire:click="editDeadline({{ $d->id }})" class="text-gray-500 underline">ändern</button>
                        <button type="button" wire:click="deleteDeadline({{ $d->id }})" wire:confirm="Frist entfernen?" class="text-gray-500 underline">entfernen</button>
                    </span>
                @endif
            </div>
        @empty
            <p class="text-sm text-gray-500">Keine Fristen eingetragen.</p>
        @endforelse
    </section>

    <section class="{{ $card }}">
        <div class="flex items-center justify-between gap-2">
            <p class="text-sm font-semibold text-gray-900">Prüfungen, Wartungen & Reparaturen</p>
            @if($canManage && ! $recording)<button type="button" wire:click="startRecord" class="text-xs font-semibold text-fw-navy underline">Eintrag hinzufügen</button>@endif
        </div>
        @if($recording)
            <div class="grid grid-cols-1 gap-2 rounded-lg bg-gray-50 p-3 sm:grid-cols-2">
                <label class="block text-xs text-gray-600">Art
                    <select wire:model="record.kind" class="{{ $input }}">@foreach(\Modules\Fahrzeuge\src\Models\Record::KINDS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                </label>
                <label class="block text-xs text-gray-600">Für Frist (optional)
                    <select wire:model="record.deadline_id" class="{{ $input }}"><option value="">–</option>@foreach($deadlines as $d)<option value="{{ $d->id }}">{{ $d->title }}</option>@endforeach</select>
                </label>
                <label class="block text-xs text-gray-600 sm:col-span-2">Was wurde gemacht?<input type="text" wire:model="record.title" maxlength="150" placeholder="z. B. Hauptuntersuchung ohne Mängel, Bremsbeläge vorne erneuert" class="{{ $input }}"></label>
                <label class="block text-xs text-gray-600">Datum<input type="date" wire:model="record.done_on" class="{{ $input }}"></label>
                <label class="block text-xs text-gray-600">Kilometerstand<input type="number" wire:model="record.mileage_km" class="{{ $input }}"></label>
                <label class="block text-xs text-gray-600">Kosten in €<input type="text" inputmode="decimal" wire:model="record.cost" placeholder="0,00" class="{{ $input }}"></label>
                <label class="block text-xs text-gray-600">Werkstatt / Prüfer<input type="text" wire:model="record.provider" maxlength="150" class="{{ $input }}"></label>
                <label class="block text-xs text-gray-600 sm:col-span-2">Notizen<textarea wire:model="record.notes" rows="2" class="{{ $input }}"></textarea></label>
                <label class="block text-xs text-gray-600 sm:col-span-2">Prüfbericht / Rechnung (Fotos oder PDF)<input type="file" wire:model="uploads" multiple accept="image/*,application/pdf" class="mt-1 text-sm"></label>
                @foreach(['record_kind', 'record_title', 'record_done_on', 'record_mileage', 'record_cost', 'record_files', 'uploads.*'] as $field)@error($field)<p class="{{ $error }} sm:col-span-2">{{ $message }}</p>@enderror @endforeach
                <div class="flex gap-3 sm:col-span-2"><button type="button" wire:click="saveRecord" wire:loading.attr="disabled" class="rounded-lg bg-fw-red px-3 py-1.5 text-sm font-semibold text-white">Speichern</button><button type="button" wire:click="cancel" class="text-sm text-gray-500 underline">Abbrechen</button></div>
            </div>
        @endif
        @forelse($records as $r)
            <div class="border-t border-gray-100 pt-2 text-sm first:border-0 first:pt-0" wire:key="r-{{ $r->id }}">
                <p class="text-gray-900">{{ $r->title }}</p>
                <p class="text-xs text-gray-500">{{ $r->done_on->format('d.m.Y') }} · {{ $r->kindLabel() }}{{ $r->mileage_km ? ' · '.number_format($r->mileage_km, 0, ',', '.').' km' : '' }}{{ $r->cost() ? ' · '.$r->cost() : '' }}{{ $r->provider ? ' · '.$r->provider : '' }}</p>
                @if($r->notes)<p class="whitespace-pre-line text-xs text-gray-600">{{ $r->notes }}</p>@endif
                @if($r->attachments->isNotEmpty())<p class="text-xs">@foreach($r->attachments as $a)<a href="{{ route('fahrzeuge.attachment', $a) }}" target="_blank" class="mr-2 text-fw-navy underline">{{ $a->name }}</a>@endforeach</p>@endif
            </div>
        @empty
            <p class="text-sm text-gray-500">Noch nichts dokumentiert.</p>
        @endforelse
    </section>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <section class="{{ $card }}">
            <p class="text-sm font-semibold text-gray-900">Einsätze{{ $einsaetze->isNotEmpty() ? ' ('.$einsatzThisYear.' in '.now('Europe/Berlin')->year.')' : '' }}</p>
            @forelse($einsaetze->take(15) as $e)
                <p class="text-sm">
                    @if($canSeeReports)<a href="{{ route('einsatzbericht.show', $e->report_id) }}" class="underline">{{ $e->number }}</a>@else{{ $e->number }}@endif
                    · {{ $berlin($e->alarmed_at)->format('d.m.Y H:i') }} · {{ $e->keyword ?? '–' }}<span class="text-xs text-gray-500"> · {{ $e->crew }} Pers.</span>
                </p>
            @empty
                <p class="text-sm text-gray-500">Keine Einsätze im Einsatzbericht.</p>
            @endforelse
        </section>

        @if($bookings !== null)
            <section class="{{ $card }}">
                <p class="text-sm font-semibold text-gray-900">Buchungen</p>
                @forelse($bookings as $b)
                    <p class="text-sm">{{ $b->starts_at->setTimezone('Europe/Berlin')->format('d.m. H:i') }} – {{ $b->ends_at->setTimezone('Europe/Berlin')->format('d.m. H:i') }} · {{ $b->purpose }}<span class="text-xs text-gray-500"> · {{ $b->user->name }}{{ $b->isPending() ? ' (angefragt)' : '' }}</span></p>
                @empty
                    <p class="text-sm text-gray-500">Keine kommenden Buchungen.</p>
                @endforelse
                <a href="{{ route('fahrzeugbuchung.calendar') }}" class="text-xs text-fw-navy underline">Zum Kalender</a>
            </section>
        @endif
    </div>

    @if(app(\App\Services\ModuleRegistry::class)->isActive('fahrtenbuch') && auth()->user()->canUseModule('fahrtenbuch'))
        @include('fahrtenbuch::partials.vehicle-section', ['vehicle' => $vehicle])
    @endif

    {{-- Who may drive it, from the checked Führerscheine (if that module is on) --}}
    @include('fuehrerscheine::partials.drivers', ['vehicleId' => $vehicle->id])

    {{-- Vehicle keys, when the Schlüsselverwaltung is on --}}
    @if(app(\App\Services\ModuleRegistry::class)->isActive('schluessel') && auth()->user()->canUseModule('schluessel'))
        @include('schluessel::partials.keys-for', ['column' => 'vehicle_id', 'id' => $vehicle->id, 'class' => $card])
    @endif

    @if($equipment !== null)
        <section class="{{ $card }}">
            <p class="text-sm font-semibold text-gray-900">Beladung (Ausrüstung)</p>
            <p class="text-sm text-gray-700">{{ $equipment->count() }} Geräte an Bord{{ ($n = $equipment->filter->inspectionOverdue()->count()) ? ', '.$n.' mit überfälliger Prüfung' : '' }}{{ ($m = $equipment->reject->isUsable()->count()) ? ', '.$m.' nicht einsatzbereit' : '' }}.</p>
            @if(auth()->user()->canManageAusruestung())<a href="{{ \Modules\Ausruestung\src\Filament\Pages\LoadPlanPage::getUrl(panel: 'ausruestung') }}" class="text-xs text-fw-navy underline">Beladeplan</a>@endif
        </section>
    @endif

    @if($events->isNotEmpty())
        <section>
            <p class="mb-1 text-sm font-semibold text-gray-700">Statusverlauf</p>
            <ul class="space-y-0.5 text-xs text-gray-600">
                @foreach($events as $ev)<li>{{ $ev->created_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }} · {{ $ev->label() }}{{ $ev->note ? ': '.$ev->note : '' }} · {{ $ev->user?->name ?? 'System' }}</li>@endforeach
            </ul>
        </section>
    @endif
</div>

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
            @if($canManage)<a href="{{ route('fahrzeuge.edit', $vehicle) }}" class="mt-1 block text-xs text-fw-navy underline">Stammdaten bearbeiten</a>
                <a href="{{ route('fahrzeuge.label', $vehicle) }}" target="_blank" class="block text-xs text-fw-navy underline">QR-Etikett drucken</a>@endif
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
                'Standort' => config('locations.enabled') ? ($vehicle->standort?->name ?? 'Ohne Standort') : null,
                'Kilometerstand' => $profile->mileage_km !== null ? number_format($profile->mileage_km, 0, ',', '.').' km'.($profile->mileage_on ? ' ('.$profile->mileage_on->format('d.m.Y').')' : '') : null,
                'Betriebsstunden' => $profile->operating_hours !== null ? str_replace('.', ',', (string) $profile->operating_hours).' h' : null,
                'Beschafft' => $profile->purchased_on?->format('d.m.Y'), 'Lieferant' => $profile->supplier,
                'Kaufpreis' => $canManage && $profile->purchase_price_cents !== null ? \App\Support\Money::format($profile->purchase_price_cents) : null,
                'Förderung' => $profile->funding,
                'Ersatzbeschaffung' => $profile->replacement_year ? $profile->replacement_year.($canManage && $profile->replacement_cost_cents ? ' (ca. '.\App\Support\Money::format($profile->replacement_cost_cents).')' : '') : null,
                'Garantie bis' => $profile->warranty_until ? $profile->warranty_until->format('d.m.Y').($profile->warranty_until->isPast() ? ' (abgelaufen)' : '') : null] as $label => $value)
                @if(filled($value))<dt class="text-gray-500">{{ $label }}</dt><dd>{{ $value }}</dd>@endif
            @endforeach
        </dl>
        @if($profile->features)
            <div class="flex flex-wrap gap-1.5 pt-1">@foreach($profile->features as $feature)<span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs text-gray-700">{{ $feature }}</span>@endforeach</div>
        @endif
        @if($profile->notes)<p class="whitespace-pre-line pt-1 text-sm text-gray-700">{{ $profile->notes }}</p>@endif
        @if(! $profile->exists)<p class="text-sm text-gray-500">Noch keine Stammdaten.@if($canManage) <a href="{{ route('fahrzeuge.edit', $vehicle) }}" class="underline">Jetzt erfassen</a>@endif</p>@endif
    </section>

    @if($defects !== null)
        <section class="{{ $card }}">
            <div class="flex items-center justify-between gap-2">
                <p class="text-sm font-semibold text-gray-900">Offene Mängel</p>
                <a href="{{ route('maengelmanagement.report', ['fahrzeug' => $vehicle->id]) }}" class="text-xs font-semibold text-fw-navy underline">Mangel melden</a>
            </div>
            @forelse($defects as $m)
                <p class="text-sm"><a href="{{ route('maengelmanagement.show', $m) }}" class="underline">{{ $m->title }}</a>
                    <span class="text-xs text-gray-500"> · {{ $m->urgency->label() }} · {{ $m->status->label() }} · {{ $m->created_at->setTimezone('Europe/Berlin')->format('d.m.Y') }}</span></p>
            @empty
                <p class="text-sm text-gray-500">Keine.</p>
            @endforelse
        </section>
    @endif

    @if($documents->isNotEmpty() || $canManage)
        <section class="{{ $card }}">
            <p class="text-sm font-semibold text-gray-900">Dokumente</p>
            @forelse($documents as $doc)
                <div class="flex flex-wrap items-center justify-between gap-2 text-sm" wire:key="doc-{{ $doc->id }}">
                    <span><a href="{{ route('fahrzeuge.document', $doc) }}" target="_blank" class="underline">{{ $doc->title }}</a>
                        <span class="text-xs text-gray-500"> · {{ $doc->kindLabel() }}{{ $doc->internal ? ' · nur Fahrzeugwarte' : '' }}</span>
                        @if($doc->valid_until)<span class="text-xs {{ $doc->isExpired() ? 'font-semibold text-red-700' : 'text-gray-500' }}"> · gültig bis {{ $doc->valid_until->format('d.m.Y') }}</span>@endif</span>
                    @if($canManage)<button type="button" wire:click="deleteDocument({{ $doc->id }})" wire:confirm="Dokument entfernen?" class="text-xs text-gray-500 underline">entfernen</button>@endif
                </div>
            @empty
                <p class="text-sm text-gray-500">Noch keine Dokumente (z. B. Fahrzeugschein, Gutachten, Betriebsanleitung).</p>
            @endforelse
            @if($canManage)
                <div class="grid grid-cols-1 gap-2 border-t border-gray-100 pt-2 sm:grid-cols-2">
                    <label class="block text-xs text-gray-600">Art<select wire:model="doc.kind" class="{{ $input }}">@foreach(\Modules\Fahrzeuge\src\Models\Document::KINDS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                    <label class="block text-xs text-gray-600">Bezeichnung (optional)<input type="text" wire:model="doc.title" maxlength="150" class="{{ $input }}"></label>
                    <label class="block text-xs text-gray-600">Gültig bis (optional)<input type="date" wire:model="doc.valid_until" class="{{ $input }}"></label>
                    <label class="block text-xs text-gray-600">Datei (Foto oder PDF)<input type="file" wire:model="docFile" accept="image/*,application/pdf" class="mt-1 text-sm"></label>
                    <label class="flex items-center gap-2 text-xs text-gray-600"><input type="checkbox" wire:model="doc.internal" class="rounded border-gray-300"> nur für Fahrzeugwarte (z. B. Kaufvertrag)</label>
                    <div><button type="button" wire:click="addDocument" wire:loading.attr="disabled" class="rounded-lg bg-fw-navy px-3 py-1.5 text-sm font-semibold text-white">Ablegen</button></div>
                    @foreach(['docFile', 'document_kind', 'document_valid_until', 'document_file'] as $field)@error($field)<p class="{{ $error }} sm:col-span-2">{{ $message }}</p>@enderror @endforeach
                </div>
            @endif
        </section>
    @endif

    @if($canManage)
        <section class="{{ $card }}">
            <p class="text-sm font-semibold text-gray-900">Status</p>
            <button type="button" wire:click="deleteVehicle" wire:confirm="{{ $vehicle->name }} endgültig löschen? Das ist nur möglich, wenn keine Buchungen oder Fahrtenbucheinträge vorhanden sind." class="text-xs text-red-700 underline">Fahrzeug löschen</button>
            @if($vehicle->is_active)
                <div class="flex flex-wrap items-end gap-2">
                    <select wire:model="status" class="rounded-lg border border-gray-300 px-3 py-2 text-sm" aria-label="Status">
                        @foreach(\Modules\Fahrzeuge\src\Models\Profile::STATUSES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                    <input type="text" wire:model="statusNote" maxlength="300" placeholder="Was ist los? (z. B. Blaulicht hinten links defekt)" class="min-w-0 flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm">
                    <button type="button" wire:click="saveStatus" class="rounded-lg bg-fw-navy px-3 py-2 text-sm font-semibold text-white">Speichern</button>
                </div>
                @error('status_note')<p class="{{ $error }}">{{ $message }}</p>@enderror
                @if($profile->status_source === 'inspection')<p class="text-xs text-gray-500">Von einer Prüfung gesetzt – eine bestandene Nachprüfung macht das Fahrzeug wieder einsatzbereit.</p>@endif
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
            <p class="text-sm font-semibold text-gray-900">Fristen & Wartungsintervalle <a href="{{ route('fahrzeuge.deadlines') }}" class="ml-1 text-xs font-normal text-fw-navy underline">alle Fahrzeuge</a></p>
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
                <label class="block text-xs text-gray-600 sm:col-span-2">Prüfschritte (einer je Zeile; leer = ohne Checkliste)<textarea wire:model="deadline.steps" rows="4" class="{{ $input }}" placeholder="z. B. Bremsanlage&#10;Beleuchtung und Sondersignal"></textarea></label>
                @foreach(['deadline_title', 'deadline_due_on', 'deadline_interval', 'deadline_lead'] as $field)@error($field)<p class="{{ $error }} sm:col-span-2">{{ $message }}</p>@enderror @endforeach
                <div class="flex gap-3 sm:col-span-2"><button type="button" wire:click="saveDeadline" class="rounded-lg bg-fw-red px-3 py-1.5 text-sm font-semibold text-white">Speichern</button><button type="button" wire:click="cancel" class="text-sm text-gray-500 underline">Abbrechen</button></div>
            </div>
        @endif
        @forelse($deadlines as $d)
            <div class="flex flex-wrap items-center justify-between gap-2 text-sm" wire:key="dl-{{ $d->id }}">
                <span>{{ $d->title }}<span class="block text-xs {{ $d->isOverdue() ? 'font-semibold text-red-700' : ($d->isDue() ? 'text-amber-800' : 'text-gray-500') }}">{{ $d->isOverdue() ? 'überfällig seit' : 'fällig' }} {{ $d->due_on->format('d.m.Y') }}{{ $d->interval_months ? ' · alle '.$d->interval_months.' Monate' : '' }}{{ $d->steps ? ' · '.count($d->steps).' Prüfschritte' : '' }}</span></span>
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
            <p class="text-sm font-semibold text-gray-900">Prüfungen, Wartungen & Reparaturen
                @if($costs && $costs['total'])<span class="ml-1 text-xs font-normal text-gray-500">· Kosten {{ $costs['year'] }}: {{ \App\Support\Money::format($costs['total']) }}</span>@endif</p>
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
                <label class="block text-xs text-gray-600">Rechnungsnummer<input type="text" wire:model="record.invoice_number" maxlength="60" class="{{ $input }}"></label>
                @if($costCenters !== null && $costCenters->isNotEmpty())
                    <div class="text-xs text-gray-600">
                        <label class="flex items-center gap-2 pt-5"><input type="checkbox" wire:model.live="toFinance" class="rounded border-gray-300"> Kosten bei den Finanzen als Ausgabe einreichen</label>
                        @if($toFinance)<select wire:model="costCenterId" class="{{ $input }}" aria-label="Kostenstelle"><option value="">Kostenstelle …</option>@foreach($costCenters as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>@endif
                    </div>
                @endif
                @if($checklist)
                    <div class="space-y-1 sm:col-span-2">
                        <p class="text-xs font-semibold text-gray-700">Prüfschritte</p>
                        @foreach($checklist as $i => $step)
                            <div class="grid grid-cols-1 gap-1 rounded-lg bg-white p-2 sm:grid-cols-[1fr_auto_14rem] sm:items-center" wire:key="cl-{{ $i }}">
                                <span class="text-sm">{{ $step['step'] }}</span>
                                <span class="flex gap-2 text-xs">
                                    @foreach(\Modules\Fahrzeuge\src\Models\Record::STEP_STATES as $value => $label)
                                        <label class="flex items-center gap-1 {{ $value === 'defect' ? 'text-red-700' : '' }}"><input type="radio" wire:model.live="checklist.{{ $i }}.state" value="{{ $value }}"> {{ $label }}</label>
                                    @endforeach
                                </span>
                                <input type="text" wire:model="checklist.{{ $i }}.note" maxlength="300" placeholder="Bemerkung" class="rounded-lg border border-gray-300 px-2 py-1 text-xs">
                            </div>
                        @endforeach
                    </div>
                @endif
                @if($record['kind'] === 'inspection')
                    <label class="block text-xs text-gray-600 sm:col-span-2">Ergebnis
                        <select wire:model="record.result" class="{{ $input }}"><option value="">– ohne Bewertung –</option>@foreach(\Modules\Fahrzeuge\src\Models\Record::RESULTS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                        <span class="mt-0.5 block text-gray-500">Das Ergebnis setzt die Einsatzbereitschaft. Nicht bestanden: die Frist bleibt bis zur Nachprüfung offen.@if(app(\App\Services\ModuleRegistry::class)->isActive('maengelmanagement')) Jeder Prüfschritt mit Mangel kommt ins Mängelmanagement.@endif</span>
                    </label>
                    <div class="space-y-1 sm:col-span-2">
                        <label class="block text-xs text-gray-600">Unterschrift Prüfer (optional)<input type="text" wire:model="signerName" maxlength="150" class="{{ $input }}"></label>
                        @include('partials.signature-pad', ['model' => 'signature', 'label' => 'Geprüft nach bestem Wissen'])
                    </div>
                @endif
                <label class="block text-xs text-gray-600 sm:col-span-2">Notizen<textarea wire:model="record.notes" rows="2" class="{{ $input }}"></textarea></label>
                <label class="block text-xs text-gray-600 sm:col-span-2">Prüfbericht / Rechnung (Fotos oder PDF)<input type="file" wire:model="uploads" multiple accept="image/*,application/pdf" class="mt-1 text-sm"></label>
                @foreach(['record_kind', 'record_title', 'record_done_on', 'record_mileage', 'record_cost', 'record_files', 'record_checklist', 'record_result', 'record_signature', 'record_signer', 'record_finance', 'uploads.*'] as $field)@error($field)<p class="{{ $error }} sm:col-span-2">{{ $message }}</p>@enderror @endforeach
                <div class="flex gap-3 sm:col-span-2"><button type="button" wire:click="saveRecord" wire:loading.attr="disabled" class="rounded-lg bg-fw-red px-3 py-1.5 text-sm font-semibold text-white">Speichern</button><button type="button" wire:click="cancel" class="text-sm text-gray-500 underline">Abbrechen</button></div>
            </div>
        @endif
        @forelse($records as $r)
            <div class="border-t border-gray-100 pt-2 text-sm first:border-0 first:pt-0" wire:key="r-{{ $r->id }}">
                <p class="text-gray-900">{{ $r->title }}
                    @if($r->result)<span class="ml-1 rounded-full px-2 py-0.5 text-xs {{ ['passed' => 'bg-green-100 text-green-800', 'limited' => 'bg-amber-100 text-amber-800', 'failed' => 'bg-red-100 text-red-800'][$r->result] ?? '' }}">{{ ['passed' => 'bestanden', 'limited' => 'mit Mängeln', 'failed' => 'nicht bestanden'][$r->result] ?? $r->result }}</span>@endif
                </p>
                <p class="text-xs text-gray-500">{{ $r->done_on->format('d.m.Y') }} · {{ $r->kindLabel() }}{{ $r->mileage_km ? ' · '.number_format($r->mileage_km, 0, ',', '.').' km' : '' }}{{ $r->cost() ? ' · '.$r->cost() : '' }}{{ $r->provider ? ' · '.$r->provider : '' }}{{ $r->invoice_number ? ' · Rechnung '.$r->invoice_number : '' }}{{ $r->signed_at ? ' · unterschrieben von '.$r->signer_name : '' }}</p>
                @if($r->defects())<p class="text-xs text-red-700">Mängel: {{ collect($r->defects())->pluck('step')->implode(', ') }}</p>@endif
                @if($r->checklist || $r->signed_at)<a href="{{ route('fahrzeuge.record', $r) }}" target="_blank" class="text-xs text-fw-navy underline">Prüfprotokoll</a>@endif
                @if($r->finance_entry_id && $costCenters !== null)<a href="{{ route('finanzen.entry', $r->finance_entry_id) }}" class="ml-2 text-xs text-fw-navy underline">Ausgabe bei den Finanzen</a>@endif
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
            @if(auth()->user()->canManageAusruestung())<a href="{{ \Modules\Ausruestung\src\Filament\Pages\LoadPlanPage::getUrl() }}" class="text-xs text-fw-navy underline">Beladeplan</a>@endif
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

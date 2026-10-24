@php($input = 'mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red disabled:bg-gray-50 disabled:text-gray-700')
@php($card = 'bg-white rounded-2xl border border-gray-100 shadow-sm p-4 sm:p-5 space-y-3')
@php($req = fn (string $field) => in_array($field, $required, true) ? ' *' : '')
<div class="mx-auto max-w-4xl space-y-4 pb-24">
    {{-- Header --}}
    <div>
        <a href="{{ route('einsatzbericht.index') }}" class="inline-flex items-center gap-1 rounded-lg py-1.5 pr-2 text-sm font-medium text-fw-navy hover:text-fw-red">← Einsätze</a>
        <div class="mt-1 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-gray-900">Einsatz {{ $report->number }}{{ $report->keyword ? ' · '.$report->keyword : '' }}</h1>
                <p class="text-sm text-gray-500">
                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ ['draft' => 'bg-gray-100 text-gray-700', 'active' => 'bg-red-100 text-red-800', 'closed' => 'bg-amber-100 text-amber-800', 'released' => 'bg-green-100 text-green-800'][$report->status] }}">{{ $report->statusLabel() }}</span>
                    @if($report->closed_at) · abgeschlossen {{ $report->closed_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }} von {{ $report->closer?->name }}@endif
                    @if($report->released_at) · freigegeben {{ $report->released_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }} von {{ $report->releaser?->name }}@endif
                    @if($report->released_signature) · <span class="font-semibold text-green-700">signiert</span>@endif
                    @if($seal === false) · <span class="font-semibold text-amber-700">nach der Freigabe geändert</span>@endif
                </p>
                @if($parentReport)<p class="text-sm text-gray-600">Nebenbericht{{ $report->side_label ? ' „'.$report->side_label.'“' : '' }} zu <a href="{{ route('einsatzbericht.show', $parentReport) }}" class="underline">{{ $parentReport->number }}{{ $parentReport->keyword ? ' · '.$parentReport->keyword : '' }}</a></p>@endif
            </div>
            <div class="flex flex-wrap gap-2">
                @if($report->navigationUrl())
                    <a href="{{ $report->navigationUrl() }}" target="_blank" rel="noopener" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Navigation</a>
                @endif
                <a href="{{ route('einsatzbericht.print', $report) }}" target="_blank" rel="noopener" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Drucken / PDF</a>
                {{-- QR access for neighbouring Wehren, also further down with its explanation --}}
                @if($externalUrl)
                    <a href="{{ route('einsatzbericht.qr', $report) }}" target="_blank" rel="noopener" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">QR-Code</a>
                @elseif($report->isRunning() && $canEdit)
                    <button type="button" wire:click="enableExternal" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">QR-Code erzeugen</button>
                @endif
            </div>
        </div>
    </div>

    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @error('report')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror
    @if($missing)
        <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">Zum Abschließen fehlt noch: {{ implode(', ', $missing) }}.</p>
    @endif
    @unless($canEdit)
        <p class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-700">Dieser Bericht ist {{ $report->status === 'closed' ? 'abgeschlossen' : 'freigegeben' }}. Änderungen nur noch durch die Wehrführung; jede Änderung wird mit Name und Zeit protokolliert.</p>
    @endunless

    <fieldset @disabled(! $canEdit) class="space-y-4">
        {{-- Kopfdaten --}}
        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold text-gray-900">Kopfdaten</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <label class="block text-sm text-gray-700">Laufende Nummer<input type="text" value="{{ $report->number }}" disabled class="{{ $input }}"></label>
                <label class="block text-sm text-gray-700">Einsatznummer Leitstelle{{ $req('dispatch_number') }}<input type="text" id="field-dispatch_number" wire:model="form.dispatch_number" maxlength="50" class="{{ $input }}"></label>
                <label class="block text-sm text-gray-700">Einsatzstichwort{{ $req('keyword') }}<input type="text" id="field-keyword" wire:model="form.keyword" maxlength="150" placeholder="z. B. FEU 2, TH 1" class="{{ $input }}"></label>
            </div>
            @if($report->source === 'divera')
                <div class="rounded-lg bg-blue-50 px-3 py-2 text-sm text-blue-900">
                    <p class="text-xs font-semibold uppercase tracking-wide">Aus DIVERA 24/7 angelegt{{ $report->divera_id ? ' · Alarm '.$report->divera_id : '' }}</p>
                    @if($report->alarm_message)<p class="mt-1 whitespace-pre-line">{{ $report->alarm_message }}</p>@endif
                </div>
            @endif
        </section>

        {{-- Zeiten --}}
        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold text-gray-900">Zeiten</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach(\Modules\Einsatzbericht\src\Models\Report::TIMES as $field => $label)
                    <label class="block text-sm text-gray-700">{{ $label }}{{ $req($field) }}<input type="datetime-local" id="field-{{ $field }}" wire:model="form.{{ $field }}" class="{{ $input }}"></label>
                    @error('form.'.$field)<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                @endforeach
            </div>
            <p class="text-xs text-gray-500">Dauer: {{ \Modules\Einsatzbericht\src\Models\Report::formatMinutes($report->durationMinutes()) }}</p>
        </section>

        {{-- Einsatzstelle --}}
        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold text-gray-900">Einsatzstelle</h2>
            <div class="relative">
                <label class="block text-sm text-gray-700">Adresse{{ $req('address') }}<input type="text" id="field-address" @if($canSuggest && $canEdit) wire:model.live.debounce.400ms="form.address" @else wire:model="form.address" @endif maxlength="255" autocomplete="off" placeholder="{{ $canSuggest ? 'Straße oder Ort eingeben – Vorschläge erscheinen' : 'Straße Hausnummer, Ort' }}" class="{{ $input }}"></label>
                {{-- Google suggestions: tap one, so the address is spelled right and the point is set --}}
                @if($addressSuggestions)
                    <ul class="absolute inset-x-0 top-full z-20 mt-1 divide-y divide-gray-100 overflow-hidden rounded-lg border border-gray-200 bg-white text-sm shadow-lg" role="listbox">
                        @foreach($addressSuggestions as $s)
                            <li wire:key="as-{{ $s['id'] }}"><button type="button" wire:click="chooseAddress(@js($s['id']))" class="w-full px-3 py-2 text-left hover:bg-gray-50" role="option">
                                <span class="block text-gray-900">{{ $s['main'] }}</span>@if($s['secondary'])<span class="block text-xs text-gray-500">{{ $s['secondary'] }}</span>@endif
                            </button></li>
                        @endforeach
                        <li class="px-3 py-1 text-right text-[10px] text-gray-400">Vorschläge von Google</li>
                    </ul>
                @endif
            </div>
            {{-- Map: shows the point; editable: drag the pin or tap the map --}}
            <div wire:ignore x-data="einsatzMap({ lat: @js($report->latitude), lng: @js($report->longitude), editable: @js($canEdit) })" class="overflow-hidden rounded-xl border border-gray-200">
                <div x-ref="map" class="h-56 sm:h-72 w-full"></div>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
                <span>@if($report->latitude && $report->longitude)Koordinaten {{ number_format($report->latitude, 5, ',', '') }} / {{ number_format($report->longitude, 5, ',', '') }}@else Noch kein Punkt – aus der Adresse ermittelt (Google-Schlüssel nötig) oder auf der Karte tippen.@endif</span>
                @if($canEdit && $report->latitude)<button type="button" wire:click="clearLocation" class="underline">Punkt entfernen</button>@endif
            </div>
            @if($canEdit)<p class="text-xs text-gray-500">Punkt verschieben oder auf die Karte tippen, z. B. für Feldwege oder Autobahnabschnitte ohne Adresse.</p>@endif
            <label class="block text-sm text-gray-700">Objekt<input type="text" wire:model="form.object" maxlength="255" placeholder="z. B. Wohnhaus, Scheune, B75 km 3" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Eigentümer oder Betroffene<textarea wire:model="form.affected_parties" rows="2" class="{{ $input }}"></textarea></label>
        </section>

        {{-- Einsatzart --}}
        <section id="field-category" class="{{ $card }}">
            <h2 class="text-sm font-semibold text-gray-900">Einsatzart{{ $req('category') }}</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                @foreach(\Modules\Einsatzbericht\src\Models\Report::CATEGORIES as $value => $label)
                    <label class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2.5 text-sm has-[:checked]:border-fw-red has-[:checked]:bg-red-50">
                        <input type="radio" wire:model.live="form.category" value="{{ $value }}" class="text-fw-red focus:ring-fw-red"> {{ $label }}
                    </label>
                @endforeach
            </div>
        </section>

        {{-- Guided Zusatzangaben: what this Einsatzart needs --}}
        @if($detailFields)
            <section id="field-details" class="{{ $card }}">
                <h2 class="text-sm font-semibold text-gray-900">Angaben zur Einsatzart{{ $req('details') }}</h2>
                <p class="text-xs text-gray-500">Passend zur Einsatzart; fließen in die Jahresstatistik ein.@if(in_array('details', $required, true)) Mit * markierte braucht der Abschluss.@endif</p>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    @foreach($detailFields as $key => $field)
                        @php($mark = ($field[3] ?? false) && in_array('details', $required, true) ? ' *' : '')
                        @if($field[1] === 'select')
                            <label class="block text-sm text-gray-700">{{ $field[0] }}{{ $mark }}
                                <select wire:model="form.details.{{ $key }}" class="{{ $input }}"><option value="">–</option>@foreach($field[2] as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach</select>
                            </label>
                        @elseif($field[1] === 'bool')
                            <label class="flex items-center gap-2 pt-6 text-sm text-gray-700"><input type="checkbox" wire:model="form.details.{{ $key }}" class="rounded border-gray-300 text-fw-red"> {{ $field[0] }}</label>
                        @elseif($field[1] === 'number')
                            <label class="block text-sm text-gray-700">{{ $field[0] }}<input type="number" min="0" max="9999" inputmode="numeric" wire:model="form.details.{{ $key }}" class="{{ $input }}"></label>
                        @else
                            <label class="block text-sm text-gray-700">{{ $field[0] }}
                                <input type="text" maxlength="200" wire:model="form.details.{{ $key }}" @if($field[1] === 'water' && $waterOptions) list="eb-water-sources" placeholder="Nächste Entnahmestellen aus der Wasserkarte" @endif class="{{ $input }}">
                            </label>
                            @if($field[1] === 'water' && $waterOptions)<datalist id="eb-water-sources">@foreach($waterOptions as $option)<option value="{{ $option }}"></option>@endforeach</datalist>@endif
                        @endif
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Lage und Maßnahmen --}}
        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold text-gray-900">Lage und Maßnahmen</h2>
            <label class="block text-sm text-gray-700">Lage beim Eintreffen{{ $req('situation') }}<textarea id="field-situation" wire:model="form.situation" rows="3" class="{{ $input }}"></textarea></label>
            <label class="block text-sm text-gray-700">Getroffene Maßnahmen{{ $req('measures') }}<textarea id="field-measures" wire:model="form.measures" rows="4" class="{{ $input }}"></textarea></label>
            <label class="block text-sm text-gray-700">Eingesetzte Geräte<textarea wire:model="form.equipment_used" rows="2" placeholder="z. B. Wärmebildkamera, Hydraulischer Rettungssatz" class="{{ $input }}"></textarea></label>
        </section>

        {{-- Fahrzeuge und Besatzung --}}
        <section id="field-crew" class="{{ $card }}">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-gray-900">Fahrzeuge und Besatzung</h2>
                <span class="rounded-full bg-fw-navy px-3 py-1 text-sm font-semibold text-white" title="Führung/Unterführer/Mannschaft/Gesamt an der Einsatzstelle">Stärke {{ $strength['label'] }}</span>
            </div>
            <p class="text-xs text-gray-500">Jede Einsatzkraft gehört zu genau einem Fahrzeug oder zur Bereitschaft. Wer schon anders eingeteilt ist, wechselt. Die Bereitschaft am Gerätehaus zählt als Einsatzteilnahme, aber nicht zur Stärke.</p>

            @foreach($report->vehicles as $vehicle)
                @php($vs = \Modules\Einsatzbericht\src\Services\ReportService::strength($vehicle->crew))
                <div class="rounded-xl border {{ $vehicle->is_standby ? 'border-dashed border-gray-300 bg-gray-50' : 'border-gray-200' }} p-3 space-y-3" wire:key="v-{{ $vehicle->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="font-semibold text-gray-900">{{ $vehicle->name }} @unless($vehicle->is_standby)<span class="ml-1 text-sm font-normal text-gray-500">{{ $vs['label'] }}</span>@endunless</p>
                        @if($canEdit)<button type="button" wire:click="removeVehicle({{ $vehicle->id }})" wire:confirm="{{ $vehicle->name }} mit Besatzung entfernen?" class="text-sm text-red-600 underline">Entfernen</button>@endif
                    </div>

                    @unless($vehicle->is_standby)
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2">
                            @foreach(\Modules\Einsatzbericht\src\Models\ReportVehicle::TIMES as $field => $label)
                                <label class="block text-xs text-gray-600">{{ $label }}<input type="datetime-local" wire:model="vehicleTimes.{{ $vehicle->id }}.{{ $field }}" wire:change="saveVehicleTimes({{ $vehicle->id }})" class="{{ $input }}"></label>
                            @endforeach
                        </div>
                    @endunless

                    <ul class="divide-y divide-gray-100">
                        @foreach($vehicle->crew->sortBy(fn ($c) => array_search($c->function, array_keys(\Modules\Einsatzbericht\src\Models\CrewMember::FUNCTIONS))) as $member)
                            {{-- Phone: name and × on top, function and Atemschutz below; wide screens: one line --}}
                            <li class="grid grid-cols-[1fr_auto] items-center gap-x-2 gap-y-1 py-2 sm:grid-cols-[minmax(0,1fr)_12rem_auto_auto]" wire:key="c-{{ $member->id }}" x-data="{ fn: @js($member->function), ba: @js($member->breathing_apparatus) }">
                                <span class="min-w-0 truncate font-medium text-gray-900">{{ $member->user->name }}</span>
                                @if($canEdit)<button type="button" wire:click="removeCrew({{ $member->id }})" class="inline-flex h-8 w-8 items-center justify-center rounded-full text-gray-400 hover:bg-gray-100 hover:text-red-600 sm:order-last" aria-label="{{ $member->user->name }} entfernen">×</button>@else<span class="sm:order-last"></span>@endif
                                <select x-model="fn" x-on:change="$wire.updateCrew({{ $member->id }}, fn, ba)" class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-base sm:text-sm" aria-label="Funktion">
                                    @foreach(\Modules\Einsatzbericht\src\Models\CrewMember::FUNCTIONS as $value => [$label])<option value="{{ $value }}">{{ $label }}</option>@endforeach
                                </select>
                                @unless($vehicle->is_standby)
                                    <label class="flex items-center gap-1.5 text-sm text-gray-700"><input type="checkbox" x-model="ba" x-on:change="$wire.updateCrew({{ $member->id }}, fn, ba)" class="h-5 w-5 rounded border-gray-300 text-fw-red focus:ring-fw-red"> Atemschutz</label>
                                @else
                                    <span></span>
                                @endunless
                            </li>
                        @endforeach
                    </ul>

                    @if($canEdit)
                        <div class="grid grid-cols-[1fr_auto] items-center gap-2 border-t border-gray-100 pt-3 sm:grid-cols-[minmax(0,1fr)_12rem_auto_auto]">
                            <select wire:model="newCrew.{{ $vehicle->id }}.user" class="col-span-2 w-full rounded-lg border border-gray-300 px-2 py-2 text-base sm:col-span-1 sm:text-sm" aria-label="Kamerad">
                                <option value="">Kamerad wählen …</option>
                                @foreach($people as $person)
                                    <option value="{{ $person->id }}">{{ $person->name }}{{ in_array($person->id, $assigned, true) ? ' (wechselt)' : '' }}</option>
                                @endforeach
                            </select>
                            <select wire:model="newCrew.{{ $vehicle->id }}.function" class="w-full rounded-lg border border-gray-300 px-2 py-2 text-base sm:text-sm" aria-label="Funktion">
                                @foreach(\Modules\Einsatzbericht\src\Models\CrewMember::FUNCTIONS as $value => [$label])<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </select>
                            @unless($vehicle->is_standby)
                                <label class="flex items-center gap-1.5 text-sm text-gray-700"><input type="checkbox" wire:model="newCrew.{{ $vehicle->id }}.ba" class="h-5 w-5 rounded border-gray-300 text-fw-red focus:ring-fw-red"> Atemschutz</label>
                            @else
                                <span></span>
                            @endunless
                            <button type="button" wire:click="addCrew({{ $vehicle->id }})" class="col-span-2 rounded-lg bg-fw-navy px-3 py-2 text-sm font-semibold text-white sm:col-span-1">Hinzufügen</button>
                        </div>
                    @endif
                </div>
            @endforeach

            @if($canEdit)
                <div class="flex flex-wrap items-center gap-2">
                    <select wire:model="newVehicle" class="min-w-0 flex-1 rounded-lg border border-gray-300 px-2 py-2 text-base sm:text-sm" aria-label="Fahrzeug">
                        <option value="">Fahrzeug hinzufügen …</option>
                        @foreach($vehicleOptions as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                        @unless($hasStandby)<option value="standby">Bereitschaft (Gerätehaus)</option>@endunless
                    </select>
                    <button type="button" wire:click="addVehicle" class="rounded-lg bg-fw-navy px-3 py-2 text-sm font-semibold text-white">Hinzufügen</button>
                </div>
            @endif
        </section>
        {{-- Optional EinsatzberichtPlus fields (see docs-extended/EINSATZBERICHT.md). --}}
        @includeIf('ebp::partials.editor', ['report' => $report, 'canEdit' => $canEdit, 'card' => $card])

        {{-- Verbrauchsmaterial --}}
        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold text-gray-900">Verbrauchsmaterial</h2>
            @if($report->materials->isNotEmpty())
                <ul class="divide-y divide-gray-100">
                    @foreach($report->materials as $item)
                        <li class="flex flex-wrap items-center gap-2 py-2 text-sm" wire:key="mat-{{ $item->id }}">
                            <span class="min-w-0 flex-1"><span class="font-medium text-gray-900">{{ $item->name }}</span> <span class="text-xs text-gray-500">{{ $item->categoryLabel() }}</span></span>
                            <input type="text" inputmode="decimal" value="{{ rtrim(rtrim(number_format($item->quantity, 2, ',', ''), '0'), ',') }}" wire:change="updateMaterial({{ $item->id }}, $event.target.value)" class="w-20 rounded-lg border border-gray-300 px-2 py-1.5 text-right text-base sm:text-sm" aria-label="Menge">
                            <span class="w-16 text-gray-600">{{ $item->unitLabel() }}</span>
                            @if($canEdit)<button type="button" wire:click="removeMaterial({{ $item->id }})" class="inline-flex h-8 w-8 items-center justify-center rounded-full text-gray-400 hover:bg-gray-100 hover:text-red-600" aria-label="{{ $item->name }} entfernen">×</button>@endif
                        </li>
                    @endforeach
                </ul>
            @endif
            @if($canEdit)
                <div class="space-y-2 border-t border-gray-100 pt-3">
                    @if($newMaterial['free'])
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                            <input type="text" wire:model="newMaterial.name" maxlength="150" placeholder="Bezeichnung" class="col-span-2 rounded-lg border border-gray-300 px-2 py-2 text-base sm:text-sm">
                            <select wire:model="newMaterial.category" class="rounded-lg border border-gray-300 px-2 py-2 text-base sm:text-sm" aria-label="Kategorie">@foreach(\Modules\Einsatzbericht\src\Models\Material::CATEGORIES as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
                            <select wire:model="newMaterial.unit" class="rounded-lg border border-gray-300 px-2 py-2 text-base sm:text-sm" aria-label="Einheit">@foreach(\Modules\Einsatzbericht\src\Models\Material::UNITS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
                        </div>
                    @else
                        <select wire:model="newMaterial.material" class="w-full rounded-lg border border-gray-300 px-2 py-2 text-base sm:text-sm" aria-label="Material">
                            <option value="">Material aus dem Katalog …</option>
                            @foreach($catalog as $category => $items)
                                <optgroup label="{{ \Modules\Einsatzbericht\src\Models\Material::CATEGORIES[$category] ?? $category }}">
                                    @foreach($items as $material)<option value="{{ $material->id }}">{{ $material->label() }}</option>@endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    @endif
                    <div class="flex flex-wrap items-center gap-2">
                        <input type="text" inputmode="decimal" wire:model="newMaterial.quantity" placeholder="Menge" class="w-28 rounded-lg border border-gray-300 px-2 py-2 text-base sm:text-sm" aria-label="Menge">
                        <button type="button" wire:click="addMaterial" class="rounded-lg bg-fw-navy px-3 py-2 text-sm font-semibold text-white">Hinzufügen</button>
                        <label class="ml-auto flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model.live="newMaterial.free" class="h-5 w-5 rounded border-gray-300 text-fw-red focus:ring-fw-red"> Nicht im Katalog</label>
                    </div>
                </div>
            @endif
        </section>

        {{-- Personen und Schäden --}}
        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold text-gray-900">Personen und Schäden</h2>
            <div class="grid grid-cols-3 gap-3">
                <label class="block text-sm text-gray-700">Gerettete<input type="number" min="0" wire:model="form.rescued" class="{{ $input }}"></label>
                <label class="block text-sm text-gray-700">Verletzte<input type="number" min="0" wire:model="form.injured" class="{{ $input }}"></label>
                <label class="block text-sm text-gray-700">Tote<input type="number" min="0" wire:model="form.dead" class="{{ $input }}"></label>
            </div>
            <label class="block text-sm text-gray-700">Sachschäden<textarea wire:model="form.property_damage" rows="2" class="{{ $input }}"></textarea></label>
            <label class="block text-sm text-gray-700">Umweltschäden<textarea wire:model="form.environmental_damage" rows="2" class="{{ $input }}"></textarea></label>
        </section>

        {{-- Beteiligte --}}
        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold text-gray-900">Beteiligte</h2>
            <div class="flex flex-wrap gap-4 text-sm text-gray-700">
                <label class="flex items-center gap-2"><input type="checkbox" wire:model="form.police" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> Polizei</label>
                <label class="flex items-center gap-2"><input type="checkbox" wire:model="form.ems" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> Rettungsdienst</label>
            </div>
            <label class="block text-sm text-gray-700">Nachbarwehren<input type="text" wire:model="form.neighbor_brigades" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Sonstige Stellen<input type="text" wire:model="form.other_parties" placeholder="z. B. Energieversorger, Straßenmeisterei" class="{{ $input }}"></label>
        </section>

        {{-- Abschluss --}}
        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold text-gray-900">Abschluss</h2>
            <label class="block text-sm text-gray-700">Übergabe der Einsatzstelle<input type="text" wire:model="form.handover" placeholder="an wen, z. B. Polizei, Eigentümer" class="{{ $input }}"></label>
            <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model.live="form.fire_watch" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> Brandwache</label>
            @if($form['fire_watch'])<label class="block text-sm text-gray-700">Brandwache: wer, bis wann<input type="text" wire:model="form.fire_watch_notes" class="{{ $input }}"></label>@endif
            <label class="block text-sm text-gray-700">Einsatzleiter{{ $req('commander_id') }}
                <select id="field-commander_id" wire:model="form.commander_id" class="{{ $input }}">
                    <option value="">–</option>
                    @foreach($people as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach
                </select>
            </label>
        </section>
    </fieldset>

    <p class="text-xs text-gray-500">* Pflichtfeld zum Abschließen (legt die Wehrführung fest).</p>

    {{-- External Wehren via QR code (large Einsätze) --}}
    <section class="{{ $card }}">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-gray-900">Externe Wehren (QR-Zugang)</h2>
            @if($externalUrl)<span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-800">aktiv</span>@endif
        </div>
        <p class="text-xs text-gray-500">Bei Großeinsätzen scannen Nachbarwehren den Code mit der Handykamera, melden sich einmal an (Wehr, Fahrzeug, Stärke) und schicken ohne Login Status und Lagemeldungen mit Foto. Sie sehen nur ihre eigenen Meldungen. Der Code gilt nur, solange der Einsatz läuft, und lässt sich sofort sperren.</p>
        @if($externalUrl)
            <div class="flex flex-wrap items-start gap-4">
                <img src="{{ $externalQr }}" alt="QR-Code" class="h-40 w-40 rounded-lg border border-gray-200 bg-white p-1">
                <div class="min-w-0 flex-1 space-y-2 text-sm">
                    <a href="{{ route('einsatzbericht.qr', $report) }}" target="_blank" rel="noopener" class="inline-flex rounded-lg border border-gray-300 bg-white px-3 py-2 font-semibold text-gray-700">QR groß anzeigen</a>
                    <p class="break-all text-xs text-gray-400">{{ $externalUrl }}</p>
                    <div class="flex flex-wrap gap-3">
                        <button type="button" wire:click="revokeExternal" wire:confirm="QR-Zugang sofort sperren?" class="text-red-600 underline">Sperren</button>
                        <button type="button" wire:click="enableExternal" wire:confirm="Neuen Code erzeugen? Der alte gilt danach nicht mehr." class="text-gray-600 underline">Neuen Code erzeugen</button>
                    </div>
                </div>
            </div>
        @elseif($report->isRunning())
            <button type="button" wire:click="enableExternal" class="rounded-lg bg-fw-navy px-4 py-2 text-sm font-semibold text-white">QR-Code erzeugen</button>
        @else
            <p class="text-sm text-gray-500">Nur für einen laufenden Einsatz (ohne Einsatzende).</p>
        @endif

        @if($externalUnits->isNotEmpty())
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach($externalUnits as $unit)
                    <li class="py-2" wire:key="ext-{{ $unit->id }}" x-data="{ open: false }">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-medium text-gray-900">{{ $unit->label() }}</span>
                            <span class="text-gray-600">
                                @if($unit->statuses->first()){{ $unit->statuses->first()->label() }} · {{ $unit->statuses->first()->created_at->setTimezone('Europe/Berlin')->format('H:i') }}@else angemeldet {{ $unit->created_at->setTimezone('Europe/Berlin')->format('H:i') }}@endif
                                @if($unit->statuses->count() > 1)<button type="button" x-on:click="open = ! open" class="ml-2 text-xs underline">Verlauf</button>@endif
                            </span>
                        </div>
                        <ul x-show="open" x-cloak class="mt-1 text-xs text-gray-500">
                            @foreach($unit->statuses as $status)<li>{{ $status->created_at->setTimezone('Europe/Berlin')->format('H:i') }} {{ $status->label() }}</li>@endforeach
                        </ul>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Einsatztagebuch --}}
    <section class="{{ $card }}">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-gray-900">Lagemeldungen</h2>
            <a href="{{ route('einsatzbericht.situation', $report) }}" class="text-sm text-fw-navy underline">Eigene Seite (für Kameraden teilen)</a>
        </div>
        @if($report->isRunning())<p class="text-xs text-gray-500">Solange der Einsatz läuft (aktuell, ohne Einsatzende), können alle Kameraden mit Zugang über „Einsätze“ Lagemeldungen mit Foto schicken.</p>@endif
        <livewire:einsatzbericht.situation-log :report="$report" :key="'log-'.$report->id" />
    </section>

    {{-- History --}}
    @if($history->isNotEmpty())
        <details class="{{ $card }}">
            <summary class="cursor-pointer text-sm font-semibold text-gray-900">Änderungen ({{ $history->count() }})</summary>
            <ul class="mt-2 space-y-1 text-xs text-gray-600">
                @foreach($history as $entry)
                    <li><span class="text-gray-900">{{ $entry->created_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}</span> · {{ $entry->user?->name ?? 'System' }} · {{ $entry->eventLabel() }} {{ \App\Models\ActivityLog::typeLabel($entry->subject_type) }}@if($entry->changesSummary()) – {{ $entry->changesSummary() }}@endif</li>
                @endforeach
            </ul>
        </details>
    @endif

    {{-- Haupt- und Nebenberichte --}}
    @if(! $report->isSide() && ($sides->isNotEmpty() || $canEdit))
        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold text-gray-900">Nebenberichte</h2>
            <p class="text-xs text-gray-500">Für große Einsätze: je Zug, Abteilung oder Abschnitt ein eigener Bericht mit eigener Besatzung. Er übernimmt die Kopfdaten; gezählt wird der Einsatz einmal, Stunden und Atemschutz aller Berichte zählen mit.</p>
            @foreach($sides as $side)
                @php($s = \Modules\Einsatzbericht\src\Services\ReportService::reportStrength($side))
                <p class="text-sm"><a href="{{ route('einsatzbericht.show', $side) }}" class="underline">{{ $side->number }}</a>{{ $side->side_label ? ' · '.$side->side_label : '' }}
                    <span class="text-xs text-gray-500">· {{ $side->statusLabel() }} · Stärke {{ $s['label'] }}</span></p>
            @endforeach
            @if($sides->isNotEmpty())
                @php($all = \Modules\Einsatzbericht\src\Services\ReportService::strength($sides->concat([$report])->flatMap(fn ($r) => $r->vehicles->reject->is_standby->flatMap->crew)))
                <p class="text-sm font-semibold text-gray-900">Gesamtstärke mit Nebenberichten: {{ $all['label'] }}</p>
            @endif
            @if($canEdit)
                <div class="flex flex-wrap gap-2">
                    <input type="text" wire:model="sideLabel" maxlength="150" placeholder="z. B. Löschzug Nord, Abschnitt B" class="min-w-0 flex-1 rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm">
                    <button type="button" wire:click="createSide" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700">Nebenbericht anlegen</button>
                </div>
            @endif
        </section>
    @endif

    {{-- Signing: the Einsatzleiter when closing, the Wehrführung when releasing --}}
    @if(in_array($report->status, \Modules\Einsatzbericht\src\Models\Report::OPEN, true) || ($canRelease && $report->status === 'closed'))
        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold text-gray-900">Unterschrift (optional)</h2>
            <p class="text-xs text-gray-500">{{ $report->status === 'closed' ? 'Mit der Freigabe unterschreibt die Wehrführung; eine Prüfsumme hält den freigegebenen Stand fest – spätere Korrekturen sind daran erkennbar.' : 'Mit dem Abschließen unterschreibt der Einsatzleiter den Bericht.' }}</p>
            @include('partials.signature-pad', ['model' => 'signature', 'label' => $report->status === 'closed' ? 'Geprüft und freigegeben – '.auth()->user()->name : 'Bericht richtig und vollständig – '.auth()->user()->name])
        </section>
    @endif

    {{-- Actions, always reachable on the phone. After saving: a short note, and a jump to the first empty mandatory field --}}
    <div class="fixed inset-x-0 bottom-0 z-30 border-t border-gray-200 bg-white/95 px-4 py-3 backdrop-blur"
         x-data="{ note: null, warn: false, timer: null }"
         x-on:report-saved.window="
            note = $event.detail.message; warn = $event.detail.warn;
            clearTimeout(timer); timer = setTimeout(() => note = null, warn ? 6000 : 3000);
            const el = $event.detail.field && document.getElementById('field-' + $event.detail.field);
            if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                const input = el.matches('input, select, textarea') ? el : el.querySelector('input, select, textarea');
                input?.focus({ preventScroll: true });
                el.classList.add('ring-2', 'ring-amber-400', 'rounded-lg');
                setTimeout(() => el.classList.remove('ring-2', 'ring-amber-400', 'rounded-lg'), 2500);
            }">
        <p x-show="note" x-cloak x-transition.opacity role="status" aria-live="polite"
           class="mx-auto mb-2 max-w-4xl rounded-lg px-3 py-2 text-sm font-medium"
           :class="warn ? 'bg-amber-50 text-amber-900 ring-1 ring-amber-200' : 'bg-green-50 text-green-800 ring-1 ring-green-200'" x-text="note"></p>
        <div class="mx-auto flex max-w-4xl flex-wrap items-center gap-2">
            @if($canEdit && $report->status === 'draft')
                <button type="button" wire:click="start" wire:loading.attr="disabled" class="rounded-lg bg-fw-red px-4 py-2.5 text-sm font-semibold text-white">Einsatz starten</button>
                <button type="button" wire:click="save" wire:loading.attr="disabled" class="rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700">Entwurf speichern</button>
            @elseif($canEdit)
                <button type="button" wire:click="save" wire:loading.attr="disabled" class="rounded-lg bg-fw-navy px-4 py-2.5 text-sm font-semibold text-white">Speichern</button>
            @endif
            @if(in_array($report->status, \Modules\Einsatzbericht\src\Models\Report::OPEN, true))
                <button type="button" wire:click="close" wire:confirm="Bericht abschließen? Danach kann ihn nur noch die Wehrführung ändern." class="rounded-lg {{ $report->status === 'draft' ? 'border border-gray-300 bg-white text-gray-700' : 'bg-fw-red text-white' }} px-4 py-2.5 text-sm font-semibold">Abschließen</button>
            @endif
            @if($canRelease && $report->status === 'closed')
                <button type="button" wire:click="release" class="rounded-lg bg-green-600 px-4 py-2.5 text-sm font-semibold text-white">Freigeben</button>
            @endif
            @if($canRelease && in_array($report->status, \Modules\Einsatzbericht\src\Models\Report::DONE, true))
                <button type="button" wire:click="reopen" wire:confirm="Bericht wieder öffnen? Der Einsatzleiter kann ihn dann wieder bearbeiten." class="text-sm text-gray-600 underline">Wieder öffnen</button>
            @endif
            @if(in_array($report->status, \Modules\Einsatzbericht\src\Models\Report::OPEN, true) || $canRelease)
                <button type="button" wire:click="delete" wire:confirm="Einsatz {{ $report->number }} endgültig löschen?" class="ml-auto text-sm text-red-600 underline">Löschen</button>
            @endif
        </div>
    </div>
</div>

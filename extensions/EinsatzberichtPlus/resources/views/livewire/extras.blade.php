@php
    $card = 'rounded-xl border border-gray-200 bg-white p-4 shadow-sm space-y-3';
    $input = 'w-full rounded-lg border border-gray-300 px-2 py-2 text-base sm:text-sm';
    $btn = 'rounded-lg bg-fw-navy px-3 py-2 text-sm font-semibold text-white';
@endphp
<div class="space-y-4" data-ebp>
    <section class="{{ $card }}">
        <h2 class="text-base font-semibold text-fw-navy">Kostenpflichtiger Einsatz</h2>
        <label class="flex items-center gap-2 text-sm text-gray-800">
            <input type="checkbox" wire:model="chargeable" @disabled(! $canEdit) class="h-5 w-5 rounded border-gray-300 text-fw-red focus:ring-fw-red">
            Der Einsatz ist kostenpflichtig (Kostenersatz prüfen)
        </label>
        <div class="grid gap-2 sm:grid-cols-2">
            <input type="text" wire:model="chargeableTo" @disabled(! $canEdit) placeholder="Kostenträger (Name / Firma)" class="{{ $input }}" aria-label="Kostenträger">
            <input type="text" wire:model="chargeableNote" @disabled(! $canEdit) placeholder="Bemerkung" class="{{ $input }}" aria-label="Bemerkung zur Kostenpflicht">
        </div>
        @error('chargeableTo')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
        @error('chargeableNote')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
        @if($canEdit)
            <button type="button" wire:click="saveCharge" class="{{ $btn }}">Speichern</button>
            @if(session('ebp-saved'))<span class="text-sm text-green-700">{{ session('ebp-saved') }}</span>@endif
        @endif
    </section>

    <section class="{{ $card }}">
        <h2 class="text-base font-semibold text-fw-navy">Beteiligte Personen</h2>
        <p class="text-xs text-gray-500">Betroffene, Geschädigte, Verursacher, Zeugen. Die Angaben werden verschlüsselt gespeichert.</p>
        @forelse($persons as $p)
            <div class="flex items-start justify-between gap-2 rounded-lg bg-gray-50 px-3 py-2 text-sm" data-person>
                <div><strong>{{ $p->name }}</strong> <span class="text-gray-500">· {{ $p->roleLabel() }}</span>
                    @if($p->contact)<div class="text-gray-600">{{ $p->contact }}</div>@endif
                    @if($p->note)<div class="text-gray-600">{{ $p->note }}</div>@endif</div>
                @if($canEdit)<button type="button" wire:click="removePerson({{ $p->id }})" wire:confirm="Person entfernen?" class="text-sm text-red-700">Entfernen</button>@endif
            </div>
        @empty
            <p class="text-sm text-gray-500">Keine Personen eingetragen.</p>
        @endforelse
        @if($canEdit)
            <div class="grid gap-2 sm:grid-cols-2">
                <select wire:model="person.role" class="{{ $input }}" aria-label="Rolle">
                    @foreach(\Extensions\EinsatzberichtPlus\Models\InvolvedPerson::ROLES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
                <input type="text" wire:model="person.name" placeholder="Name" class="{{ $input }}" aria-label="Name">
                <input type="text" wire:model="person.contact" placeholder="Anschrift / Telefon" class="{{ $input }}" aria-label="Kontakt">
                <input type="text" wire:model="person.note" placeholder="Bemerkung" class="{{ $input }}" aria-label="Bemerkung">
            </div>
            @error('person.name')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
            <button type="button" wire:click="addPerson" class="{{ $btn }}">Person hinzufügen</button>
        @endif
    </section>

    <section class="{{ $card }}">
        <h2 class="text-base font-semibold text-fw-navy">Verdienstausfall</h2>
        <p class="text-xs text-gray-500">Für Einsatzkräfte dieses Berichts, die während der Arbeitszeit ausgerückt sind.</p>
        @forelse($losses as $l)
            <div class="flex flex-wrap items-start justify-between gap-2 rounded-lg bg-gray-50 px-3 py-2 text-sm" data-loss>
                <div><strong>{{ $l->user?->name }}</strong>
                    <span class="text-gray-500">· {{ $l->hours !== null ? rtrim(rtrim(number_format((float) $l->hours, 2, ',', ''), '0'), ',').' Std.' : 'Stunden offen' }}</span>
                    @if($l->employer)<div class="text-gray-600">Arbeitgeber: {{ $l->employer }}</div>@endif
                    @if($l->note)<div class="text-gray-600">{{ $l->note }}</div>@endif</div>
                <div class="flex items-center gap-2">
                    @if($canEdit)
                        <select wire:change="setLossStatus({{ $l->id }}, $event.target.value)" class="rounded-lg border border-gray-300 px-2 py-1 text-sm" aria-label="Stand">
                            @foreach(\Extensions\EinsatzberichtPlus\Models\EarningLoss::STATUS as $value => $label)<option value="{{ $value }}" @selected($l->status === $value)>{{ $label }}</option>@endforeach
                        </select>
                        <button type="button" wire:click="removeLoss({{ $l->id }})" wire:confirm="Eintrag entfernen?" class="text-sm text-red-700">Entfernen</button>
                    @else
                        <span class="text-gray-600">{{ \Extensions\EinsatzberichtPlus\Models\EarningLoss::STATUS[$l->status] ?? $l->status }}</span>
                    @endif
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500">Kein Verdienstausfall eingetragen.</p>
        @endforelse
        @if($canEdit)
            @if($crew->isEmpty())
                <p class="text-sm text-gray-500">Zuerst Besatzung eintragen, dann kann Verdienstausfall erfasst werden.</p>
            @else
                <div class="grid gap-2 sm:grid-cols-2">
                    <select wire:model="loss.user" class="{{ $input }}" aria-label="Einsatzkraft">
                        <option value="">Einsatzkraft wählen …</option>
                        @foreach($crew as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                    <input type="text" wire:model="loss.employer" placeholder="Arbeitgeber" class="{{ $input }}" aria-label="Arbeitgeber">
                    <input type="number" step="0.25" min="0" wire:model="loss.hours" placeholder="Stunden" class="{{ $input }}" aria-label="Ausgefallene Stunden">
                    <input type="text" wire:model="loss.note" placeholder="Bemerkung" class="{{ $input }}" aria-label="Bemerkung">
                </div>
                @error('loss.user')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                @error('loss.hours')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                <button type="button" wire:click="addLoss" class="{{ $btn }}">Verdienstausfall eintragen</button>
            @endif
        @endif
    </section>

    @if($canEdit)
        <section class="{{ $card }}">
            <h2 class="text-base font-semibold text-fw-navy">Externes Fahrzeug</h2>
            <p class="text-xs text-gray-500">Fahrzeug, das nicht in der Liste steht (andere Wehr, Dritte). Die Besatzung trägst du danach oben ein.</p>
            <div class="flex flex-wrap items-center gap-2">
                <input type="text" wire:model="externalVehicle" placeholder="z. B. TLF 3000 FF Beispieldorf" class="{{ $input }} min-w-0 flex-1" aria-label="Externes Fahrzeug">
                <button type="button" wire:click="addExternalVehicle" class="{{ $btn }}">Hinzufügen</button>
            </div>
            @error('externalVehicle')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
        </section>
    @endif
</div>

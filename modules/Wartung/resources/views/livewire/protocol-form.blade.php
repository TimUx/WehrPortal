@php($input = 'mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
@php($error = 'text-sm text-red-600')
<form wire:submit="save" class="mx-auto max-w-2xl space-y-4">
    <div>
        <h1 class="text-xl font-bold text-gray-900">{{ $template?->name ?? 'Ad-hoc-Wartung' }}</h1>
        <p class="text-sm text-gray-500">
            @if($task) Fällig am {{ $task->due_on->format('d.m.Y') }}. @endif
            Checkliste durchgehen, Zeit und Kosten eintragen, Fotos anhängen und unterschreiben. Danach ist das Protokoll gesperrt.
        </p>
    </div>

    <section class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">Gerät</p>
        @if($equipment)
            <div class="flex items-center justify-between gap-3 text-sm">
                <span>{{ $equipment->code }} – {{ $equipment->name }}<span class="block text-xs text-gray-500">{{ $equipment->category?->name }}{{ $equipment->serial_number ? ' · SN '.$equipment->serial_number : '' }}</span></span>
                @unless($task)<button type="button" wire:click="$set('equipmentId', '')" class="text-xs text-gray-500 underline">anderes Gerät</button>@endunless
            </div>
        @else
            <input type="search" wire:model.live.debounce.300ms="equipmentSearch" placeholder="Code, Name oder Seriennummer suchen" class="{{ $input }}">
            @if($matches->isNotEmpty())
                <ul class="divide-y divide-gray-100 rounded-lg border border-gray-200 text-sm">
                    @foreach($matches as $match)
                        <li><button type="button" wire:click="$set('equipmentId', '{{ $match->id }}')" class="w-full px-3 py-2 text-left hover:bg-gray-50">{{ $match->code }} – {{ $match->name }}</button></li>
                    @endforeach
                </ul>
            @endif
        @endif
        @error('equipment_id')<p class="{{ $error }}">{{ $message }}</p>@enderror

        @unless($task)
            <label class="block text-sm text-gray-700">Vorlage (optional)
                <select wire:model.live="templateId" class="{{ $input }}">
                    <option value="">– ohne Vorlage –</option>
                    @foreach($templates as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                </select>
            </label>
        @endunless
        @if(! $template)
            <label class="block text-sm text-gray-700">Was wurde gemacht?<input type="text" wire:model="title" maxlength="150" placeholder="z. B. Sichtprüfung nach Einsatz, Reparatur Kupplung" class="{{ $input }}"></label>
            @error('title')<p class="{{ $error }}">{{ $message }}</p>@enderror
        @endif
        <label class="block text-sm text-gray-700">Datum der Prüfung<input type="date" wire:model="performedOn" class="{{ $input }}"></label>
        @error('performed_on')<p class="{{ $error }}">{{ $message }}</p>@enderror
    </section>

    <section class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">Checkliste</p>
        @forelse($checklist as $i => $row)
            <div class="space-y-1 border-b border-gray-100 pb-3 last:border-0" wire:key="c-{{ $i }}">
                <div class="flex items-start justify-between gap-2">
                    <p class="text-sm text-gray-800">{{ $row['item'] }}</p>
                    @unless($template)<button type="button" wire:click="removeItem({{ $i }})" class="text-xs text-gray-400 underline">entfernen</button>@endunless
                </div>
                <div class="flex flex-wrap gap-2">
                    @foreach(\Modules\Wartung\src\Models\Protocol::ITEM_RESULTS as $value => $label)
                        <label class="flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-sm has-[:checked]:border-fw-navy has-[:checked]:bg-blue-50 {{ $value === 'defect' ? 'has-[:checked]:border-red-500 has-[:checked]:bg-red-50' : '' }}">
                            <input type="radio" wire:model.live="checklist.{{ $i }}.result" value="{{ $value }}" class="text-fw-navy focus:ring-fw-navy"> {{ $label }}
                        </label>
                    @endforeach
                </div>
                @if(($row['result'] ?? '') === 'defect' || filled($row['note'] ?? ''))
                    <input type="text" wire:model="checklist.{{ $i }}.note" maxlength="500" placeholder="Mangel / Bemerkung" class="{{ $input }}">
                @endif
                @error('checklist.'.$i)<p class="{{ $error }}">{{ $message }}</p>@enderror
            </div>
        @empty
            <p class="text-sm text-gray-500">Ohne Vorlage: eigene Prüfpunkte ergänzen oder nur den Befund beschreiben.</p>
        @endforelse
        @unless($template)
            <div class="flex gap-2">
                <input type="text" wire:model="newItem" wire:keydown.enter.prevent="addItem" maxlength="200" placeholder="Prüfpunkt hinzufügen" class="{{ $input }} mt-0">
                <button type="button" wire:click="addItem" class="shrink-0 rounded-lg border border-gray-300 px-3 py-2 text-sm">Hinzufügen</button>
            </div>
            <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="countsAsInspection" class="rounded text-fw-red focus:ring-fw-red"> Gilt als Prüfung (setzt die nächste Prüffrist am Gerät)</label>
        @endunless
    </section>

    <section class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">Ergebnis</p>
        <div class="grid grid-cols-2 gap-2">
            @foreach(\Modules\Wartung\src\Models\Protocol::RESULTS as $value => $label)
                <label class="flex items-center justify-center gap-2 rounded-lg border border-gray-200 px-3 py-2.5 text-sm font-medium {{ $value === 'passed' ? 'has-[:checked]:border-green-600 has-[:checked]:bg-green-50' : 'has-[:checked]:border-red-600 has-[:checked]:bg-red-50' }}">
                    <input type="radio" wire:model="result" value="{{ $value }}"> {{ $label }}
                </label>
            @endforeach
        </div>
        @error('result')<p class="{{ $error }}">{{ $message }}</p>@enderror
        <p class="text-xs text-gray-500">Nicht bestanden: Das Gerät wird in der Ausrüstungsverwaltung als defekt gekennzeichnet und die Gerätewarte bekommen eine Mail.</p>
        <label class="block text-sm text-gray-700">Befund / Bemerkungen<textarea wire:model="findings" rows="3" maxlength="5000" class="{{ $input }}"></textarea></label>
        @error('findings')<p class="{{ $error }}">{{ $message }}</p>@enderror
    </section>

    <section class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">Wer hat geprüft?</p>
        <div class="flex gap-2">
            @foreach(\Modules\Wartung\src\Models\Template::PERFORMERS as $value => $label)
                <label class="flex flex-1 items-center justify-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm has-[:checked]:border-fw-red has-[:checked]:bg-red-50">
                    <input type="radio" wire:model.live="performer" value="{{ $value }}" class="text-fw-red focus:ring-fw-red"> {{ $value === 'internal' ? 'Ich ('.auth()->user()->name.')' : $label }}
                </label>
            @endforeach
        </div>
        @if($performer === 'internal')
            <label class="block text-sm text-gray-700">Meine Qualifikation{{ $template?->required_qualification ? ' (nötig: '.$template->required_qualification.')' : ' (optional)' }}
                <select wire:model="qualificationId" class="{{ $input }}">
                    <option value="">– keine –</option>
                    @foreach($qualifications as $q)<option value="{{ $q->id }}">{{ $q->label() }}</option>@endforeach
                </select>
            </label>
            @error('qualification_id')<p class="{{ $error }}">{{ $message }}</p>@enderror
        @else
            <label class="block text-sm text-gray-700">Fachfirma
                <select wire:model="providerId" class="{{ $input }}">
                    <option value="">– bitte wählen –</option>
                    @foreach($providers as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                </select>
            </label>
            @error('provider_id')<p class="{{ $error }}">{{ $message }}</p>@enderror
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <label class="block text-sm text-gray-700">Prüfer der Fachfirma<input type="text" wire:model.blur="technician" maxlength="150" class="{{ $input }}"></label>
                <label class="block text-sm text-gray-700">Prüfer-ID / Sachkunde-Nr. (optional)<input type="text" wire:model="inspectorNumber" maxlength="50" class="{{ $input }}"></label>
            </div>
            @error('technician')<p class="{{ $error }}">{{ $message }}</p>@enderror
            <label class="block text-sm text-gray-700">Rechnung der Fachfirma in €<input type="text" inputmode="decimal" wire:model="externalCost" placeholder="0,00" class="{{ $input }}"></label>
        @endif
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="block text-sm text-gray-700">Zeitaufwand in Minuten<input type="number" min="0" max="10000" wire:model="minutes" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Material in € (optional)<input type="text" inputmode="decimal" wire:model="materialCost" placeholder="0,00" class="{{ $input }}"></label>
        </div>
        @error('minutes')<p class="{{ $error }}">{{ $message }}</p>@enderror
        @error('costs')<p class="{{ $error }}">{{ $message }}</p>@enderror
    </section>

    <section class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">Fotos und Dokumente</p>
        @if($uploads)
            <ul class="space-y-1 text-sm text-gray-700">
                @foreach($uploads as $i => $file)
                    <li class="flex items-center justify-between gap-2" wire:key="f-{{ $i }}"><span class="truncate">{{ $file->getClientOriginalName() }}</span><button type="button" wire:click="removeFile({{ $i }})" class="text-xs text-gray-500 underline">entfernen</button></li>
                @endforeach
            </ul>
        @endif
        <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">
            Foto oder PDF
            <input type="file" wire:model="uploads" accept="image/*,application/pdf" multiple class="sr-only">
        </label>
        <span wire:loading wire:target="uploads" class="text-sm text-gray-500">Wird hochgeladen …</span>
        <p class="text-xs text-gray-500">Bis zu {{ \Modules\Wartung\src\Services\WartungService::MAX_ATTACHMENTS }} Dateien, z. B. Fotos vom Mangel oder der Prüfbericht der Fachfirma.</p>
        @error('files')<p class="{{ $error }}">{{ $message }}</p>@enderror
        @error('uploads.*')<p class="{{ $error }}">{{ $message }}</p>@enderror
    </section>

    <section class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">Unterschrift des Prüfers</p>
        <div wire:key="pad">
            @include('partials.signature-pad', ['model' => 'signature', 'label' => 'Prüfer: '.($performer === 'external' ? ($technician ?: 'Prüfer der Fachfirma') : auth()->user()->name)])
        </div>
        @error('signature')<p class="{{ $error }}">{{ $message }}</p>@enderror
    </section>

    @error('protocol')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror
    <div class="flex items-center gap-3">
        <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-fw-red px-5 py-2.5 text-sm font-semibold text-white hover:bg-fw-red-dark disabled:opacity-50">Unterschreiben und speichern</button>
        <a href="{{ route('wartung.index') }}" class="text-sm text-gray-500 underline">Abbrechen</a>
    </div>
</form>

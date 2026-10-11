@php($input = 'mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
<form wire:submit="save" class="mx-auto max-w-2xl space-y-4">
    <div>
        <h1 class="text-xl font-bold text-gray-900">
            @if($entry) Buchung {{ $entry->code() }} ändern @elseif($direct) Buchung erfassen @else Ausgabe einreichen @endif
        </h1>
        <p class="text-sm text-gray-500">
            @if($direct) Einnahmen und bereits bezahlte Ausgaben direkt buchen – ohne Genehmigung.
            @elseif(! $entry) Du hast etwas für die Wehr ausgelegt oder brauchst eine Freigabe? Beleg fotografieren, Betrag und Kostenstelle angeben – Kassenwart und Wehrführung prüfen und genehmigen, der Kassenwart zahlt aus.
            @endif
        </p>
    </div>

    @if($centers->isEmpty())
        <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">Es gibt noch keine Kostenstellen.@if($canManage) <a href="{{ route('finanzen.budget') }}" class="underline">Jetzt anlegen</a>@else Bitte den Kassenwart fragen.@endif</p>
    @endif

    <section class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        @if($direct)
            <div class="flex gap-2">
                @foreach(\Modules\Finanzen\src\Models\Entry::KINDS as $value => $label)
                    <label class="flex flex-1 items-center justify-center gap-2 rounded-lg border border-gray-200 px-3 py-2.5 text-sm font-medium has-[:checked]:border-fw-red has-[:checked]:bg-red-50">
                        <input type="radio" wire:model.live="kind" value="{{ $value }}" class="text-fw-red focus:ring-fw-red"> {{ $label }}
                    </label>
                @endforeach
            </div>
        @endif
        <label class="block text-sm text-gray-700">Wofür?<input type="text" wire:model="title" maxlength="150" placeholder="{{ $kind === 'income' ? 'z. B. Spende Sparkasse, Zuschuss Gemeinde' : 'z. B. Verpflegung Übungsdienst, Ersatzteile TSF' }}" class="{{ $input }}"></label>
        @error('title')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="block text-sm text-gray-700">Betrag in €<input type="text" inputmode="decimal" wire:model="amount" placeholder="0,00" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Datum des Belegs<input type="date" wire:model="bookedOn" class="{{ $input }}"></label>
        </div>
        @error('amount')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        @error('booked_on')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        <label class="block text-sm text-gray-700">Kostenstelle
            <select wire:model="costCenterId" class="{{ $input }}">
                <option value="">– bitte wählen –</option>
                @foreach($centers as $center)<option value="{{ $center->id }}">{{ $center->label() }}</option>@endforeach
            </select>
        </label>
        @error('cost_center_id')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        <label class="block text-sm text-gray-700">{{ $kind === 'income' ? 'Von wem (optional)' : 'Händler / Firma (optional)' }}<input type="text" wire:model="vendor" maxlength="150" class="{{ $input }}"></label>
        <label class="block text-sm text-gray-700">Bemerkung (optional)<textarea wire:model="description" rows="2" placeholder="{{ $direct ? '' : 'z. B. Erstattung bitte auf mein Konto, Anlass, wer dabei war' }}" class="{{ $input }}"></textarea></label>
        @if($direct || ($entry && $canManage))
            <label class="block text-sm text-gray-700">Zahlungshinweis (optional)<input type="text" wire:model="paymentNote" maxlength="150" placeholder="z. B. Überweisung vom 12.05., Barkasse" class="{{ $input }}"></label>
        @endif
    </section>

    <section class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">Beleg{{ $direct || $entry ? 'e' : '' }}</p>
        @if($existing->isNotEmpty())
            <ul class="space-y-1 text-sm">
                @foreach($existing as $receipt)<li><a href="{{ route('finanzen.receipt', $receipt) }}" target="_blank" class="text-fw-navy underline">{{ $receipt->name }}</a></li>@endforeach
            </ul>
        @endif
        @if($uploads)
            <ul class="space-y-1 text-sm text-gray-700">
                @foreach($uploads as $i => $file)
                    <li class="flex items-center justify-between gap-2" wire:key="f-{{ $i }}"><span class="truncate">{{ $file->getClientOriginalName() }}</span><button type="button" wire:click="removeFile({{ $i }})" class="text-xs text-gray-500 underline">entfernen</button></li>
                @endforeach
            </ul>
        @endif
        <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 0 1 2-2h.9a2 2 0 0 0 1.7-.9l.8-1.2A2 2 0 0 1 10.1 4h3.8a2 2 0 0 1 1.7.9l.8 1.2a2 2 0 0 0 1.7.9h.9a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9z"/><circle cx="12" cy="13" r="3"/></svg>
            Foto oder PDF
            <input type="file" wire:model="uploads" accept="image/*,application/pdf" multiple class="sr-only">
        </label>
        <span wire:loading wire:target="uploads" class="text-sm text-gray-500">Wird hochgeladen …</span>
        <p class="text-xs text-gray-500">Bis zu {{ \Modules\Finanzen\src\Services\FinanceService::MAX_RECEIPTS }} Belege; Fotos werden verkleinert, PDFs bis 10 MB.</p>
        @error('uploads')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        @error('uploads.*')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
    </section>

    @error('entry')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror
    <div class="flex items-center gap-3">
        <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-fw-red px-5 py-2.5 text-sm font-semibold text-white hover:bg-fw-red-dark disabled:opacity-50">
            @if($entry) Speichern @elseif($direct) Buchen @else Einreichen @endif
        </button>
        <a href="{{ $entry ? route('finanzen.entry', $entry) : route('finanzen.index') }}" class="text-sm text-gray-500 underline">Abbrechen</a>
    </div>
</form>

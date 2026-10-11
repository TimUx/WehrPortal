<div class="space-y-4">
    <div class="flex items-center justify-between gap-2">
        <h1 class="text-xl font-bold text-gray-900">Buchungskonflikt</h1>
        @if($decision->isPending())
        <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-fw-red text-white">Entscheidung offen</span>
        @else
        <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-gray-100 text-gray-700">{{ $decision->statusLabel() }}</span>
        @endif
    </div>

    <x-fahrzeugbuchung::booking-card :booking="$request" :label="$decision->replacedBooking ? 'Änderungsanfrage' : 'Anfrage'" :highlight="$decision->isPending()">
        <div class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
            <span class="font-medium">Begründung:</span> {{ $decision->reason }}
        </div>
    </x-fahrzeugbuchung::booking-card>

    @if($decision->replacedBooking)
    <x-fahrzeugbuchung::booking-card :booking="$decision->replacedBooking" label="Bisherige Buchung – wird bei Genehmigung ersetzt" />
    @endif

    @foreach($conflicts as $existing)
    <x-fahrzeugbuchung::booking-card :booking="$existing" label="Bestehende Buchung" />
    @endforeach

    @if($decision->isPending())
    <div class="rounded-2xl border border-gray-200 bg-white p-4 space-y-3">
        <label for="note" class="block text-sm font-medium text-gray-700">Anmerkung (optional, geht an beide Seiten)</label>
        <textarea
            id="note"
            wire:model="note"
            rows="2"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-fw-navy focus:border-transparent resize-none"
        ></textarea>
        @error('decision') <p class="text-sm text-fw-red">{{ $message }}</p> @enderror

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            <button
                wire:click="approve"
                wire:confirm="Anfrage genehmigen? Die bestehende Buchung wird storniert."
                wire:loading.attr="disabled"
                class="py-2.5 px-4 rounded-xl bg-fw-red hover:bg-fw-red-dark text-sm font-semibold text-white transition-colors"
            >
                Anfrage genehmigen
            </button>
            <button
                wire:click="reject"
                wire:confirm="Bestehende Buchung behalten und die Anfrage ablehnen?"
                wire:loading.attr="disabled"
                class="py-2.5 px-4 rounded-xl bg-fw-red hover:bg-fw-red-dark text-sm font-semibold text-white transition-colors"
            >
                Bestehende Buchung behalten
            </button>
        </div>
        <p class="text-xs text-gray-500">
            Genehmigen storniert die bestehende Buchung. Beide Seiten und die anderen Entscheider bekommen eine E-Mail.
        </p>
    </div>
    @else
    <div class="rounded-2xl border border-gray-200 bg-white p-4 text-sm space-y-1">
        <p class="font-semibold text-gray-900">{{ $decision->statusLabel() }}</p>
        @if($decision->decider)
        <p class="text-gray-600">
            Entschieden von {{ $decision->decider->name }}
            am {{ $decision->decided_at->setTimezone('Europe/Berlin')->isoFormat('D. MMM YYYY, HH:mm') }} Uhr
        </p>
        @elseif($decision->decided_at)
        <p class="text-gray-600">am {{ $decision->decided_at->setTimezone('Europe/Berlin')->isoFormat('D. MMM YYYY, HH:mm') }} Uhr</p>
        @endif
        @if($decision->decision_note)
        <p class="text-gray-700"><span class="font-medium">Anmerkung:</span> {{ $decision->decision_note }}</p>
        @endif
    </div>

    @if($decision->isDecided() && !$request->isCancelled())
    <div class="rounded-2xl border border-gray-200 bg-white p-4 space-y-3">
        <p class="text-sm font-semibold text-gray-900">Entscheidung ändern</p>
        <label for="note" class="block text-sm text-gray-700">Anmerkung (optional, geht an alle Beteiligten)</label>
        <textarea
            id="note"
            wire:model="note"
            rows="2"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-fw-navy focus:border-transparent resize-none"
        ></textarea>
        @error('decision') <p class="text-sm text-fw-red">{{ $message }}</p> @enderror

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            @if($decision->status === \Modules\Fahrzeugbuchung\src\Models\BookingDecision::STATUS_APPROVED)
            <button
                wire:click="change"
                wire:confirm="Entscheidung ändern? Die Anfrage wird abgelehnt und die bestehende Buchung wiederhergestellt."
                wire:loading.attr="disabled"
                class="py-2.5 px-4 rounded-xl bg-fw-red hover:bg-fw-red-dark text-sm font-semibold text-white"
            >
                Stattdessen bestehende Buchung behalten
            </button>
            @else
            <button
                wire:click="change"
                wire:confirm="Entscheidung ändern? Die Anfrage wird genehmigt und die bestehende Buchung storniert."
                wire:loading.attr="disabled"
                class="py-2.5 px-4 rounded-xl bg-fw-red hover:bg-fw-red-dark text-sm font-semibold text-white"
            >
                Stattdessen Anfrage genehmigen
            </button>
            @endif
            <button
                wire:click="reopen"
                wire:confirm="Entscheidung zurücknehmen? Die Anfrage ist dann wieder offen."
                wire:loading.attr="disabled"
                class="py-2.5 px-4 rounded-xl border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50"
            >
                Entscheidung zurücknehmen
            </button>
        </div>
        <p class="text-xs text-gray-500">Alle Beteiligten bekommen eine E-Mail.</p>
    </div>
    @endif
    @endif

    @if(!empty($decision->history))
    <div class="rounded-2xl border border-gray-200 bg-white p-4">
        <p class="text-sm font-semibold text-gray-900 mb-2">Verlauf</p>
        <ol class="space-y-2">
            @foreach(array_reverse($decision->history) as $entry)
            <li class="text-sm">
                <p class="text-gray-900">{{ \Modules\Fahrzeugbuchung\src\Models\BookingDecision::HISTORY_LABELS[$entry['action']] ?? $entry['action'] }}</p>
                <p class="text-xs text-gray-500">
                    {{ \Carbon\Carbon::parse($entry['at'])->setTimezone('Europe/Berlin')->isoFormat('D. MMM YYYY, HH:mm') }} Uhr
                    · {{ $entry['by_name'] ?? 'automatisch' }}
                </p>
                @if(!empty($entry['note']))
                <p class="text-xs text-gray-600 mt-0.5">{{ $entry['note'] }}</p>
                @endif
            </li>
            @endforeach
        </ol>
    </div>
    @endif
</div>

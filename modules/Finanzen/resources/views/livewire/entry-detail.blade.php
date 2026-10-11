@php($input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red')
<div class="mx-auto max-w-3xl space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm text-gray-500">{{ \Modules\Finanzen\src\Models\Entry::KINDS[$entry->kind] }} · Beleg {{ $entry->code() }}</p>
            <h1 class="text-xl font-bold text-gray-900">{{ $entry->title }}</h1>
        </div>
        <div class="text-right">
            <p class="text-2xl font-bold {{ $entry->isIncome() ? 'text-green-700' : 'text-gray-900' }}">{{ $entry->amount() }}</p>
            @include('finanzen::partials.status', ['entry' => $entry])
        </div>
    </div>

    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @error('entry')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror
    @if($closed)<p class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-700">Das Jahr {{ $entry->year }} ist abgeschlossen – die Buchung lässt sich nicht mehr ändern.</p>@endif

    <section class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <dl class="grid grid-cols-1 gap-x-4 gap-y-2 text-sm sm:grid-cols-[10rem_1fr]">
            <dt class="text-gray-500">Kostenstelle</dt><dd>{{ $entry->costCenter->label() }}</dd>
            <dt class="text-gray-500">Datum des Belegs</dt><dd>{{ $entry->booked_on->format('d.m.Y') }}</dd>
            @if($entry->vendor)<dt class="text-gray-500">{{ $entry->isIncome() ? 'Von' : 'Händler' }}</dt><dd>{{ $entry->vendor }}</dd>@endif
            @if($entry->description)<dt class="text-gray-500">Bemerkung</dt><dd class="whitespace-pre-line">{{ $entry->description }}</dd>@endif
            <dt class="text-gray-500">{{ $entry->status === 'submitted' || ! $entry->isIncome() ? 'Eingereicht' : 'Gebucht' }}</dt>
            <dd>{{ $entry->submitter?->name ?? '–' }}, {{ $entry->created_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}</dd>
            @if($entry->decided_at)
                <dt class="text-gray-500">{{ $entry->status === 'rejected' ? 'Abgelehnt' : 'Genehmigt' }}</dt>
                <dd>{{ $entry->decider?->name }}, {{ $entry->decided_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}@if($entry->decision_comment)<span class="block text-gray-600">„{{ $entry->decision_comment }}“</span>@endif</dd>
            @endif
            @if($entry->paid_at && ! $entry->isIncome())
                <dt class="text-gray-500">Bezahlt</dt>
                <dd>{{ $entry->payer?->name }}, {{ $entry->paid_at->setTimezone('Europe/Berlin')->format('d.m.Y') }}@if($entry->payment_note) · {{ $entry->payment_note }}@endif</dd>
            @elseif($entry->payment_note)
                <dt class="text-gray-500">Zahlungshinweis</dt><dd>{{ $entry->payment_note }}</dd>
            @endif
        </dl>
    </section>

    <section class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="mb-2 text-sm font-semibold text-gray-900">Belege</p>
        @if($receipts->isEmpty())
            <p class="text-sm text-gray-500">Kein Beleg angehängt.</p>
        @else
            <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                @foreach($receipts as $receipt)
                    <li class="space-y-1" wire:key="r-{{ $receipt->id }}">
                        <a href="{{ route('finanzen.receipt', $receipt) }}" target="_blank" class="block overflow-hidden rounded-lg border border-gray-200 bg-gray-50">
                            @if($receipt->isImage())
                                <img src="{{ route('finanzen.receipt', $receipt) }}" alt="Beleg {{ $receipt->name }}" class="h-40 w-full object-cover" loading="lazy">
                            @else
                                <span class="flex h-40 items-center justify-center text-sm font-semibold text-gray-600">PDF</span>
                            @endif
                        </a>
                        <p class="flex items-center justify-between gap-2 text-xs text-gray-500">
                            <span class="truncate">{{ $receipt->name }}</span>
                            @if($canEdit)<button type="button" wire:click="removeReceipt({{ $receipt->id }})" wire:confirm="Beleg entfernen?" class="underline">entfernen</button>@endif
                        </p>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if($canDecide)
        <section class="space-y-2 rounded-2xl border border-amber-200 bg-amber-50 p-4">
            <p class="text-sm font-semibold text-amber-900">Genehmigen?</p>
            <textarea wire:model="comment" rows="2" maxlength="1000" placeholder="Kommentar (bei Ablehnung Pflicht)" class="{{ $input }}"></textarea>
            @error('comment')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="approve" class="rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700">Genehmigen</button>
                <button type="button" wire:click="reject" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Ablehnen</button>
            </div>
        </section>
    @endif

    @if($canPay)
        <section class="space-y-2 rounded-2xl border border-blue-200 bg-blue-50 p-4">
            <p class="text-sm font-semibold text-blue-900">Genehmigt – bezahlt?</p>
            <input type="text" wire:model="paymentNote" maxlength="150" placeholder="Zahlungshinweis (optional), z. B. Überweisung an {{ $entry->submitter?->name ?? 'Kamerad' }}" class="{{ $input }}">
            <button type="button" wire:click="pay" class="rounded-lg bg-fw-navy px-4 py-2 text-sm font-semibold text-white">Als bezahlt eintragen</button>
        </section>
    @endif

    <div class="flex flex-wrap items-center gap-4 text-sm">
        @if($canEdit)
            <a href="{{ route('finanzen.edit', $entry) }}" class="rounded-lg border border-gray-300 bg-white px-4 py-2 font-semibold text-gray-700 hover:bg-gray-50">Ändern</a>
            <button type="button" wire:click="delete" wire:confirm="{{ $entry->status === 'submitted' && $entry->submitted_by === auth()->id() ? 'Ausgabe zurückziehen?' : 'Buchung '.$entry->code().' endgültig löschen?' }}" class="text-red-600 underline">
                {{ $entry->status === 'submitted' && $entry->submitted_by === auth()->id() ? 'Zurückziehen' : 'Löschen' }}
            </button>
        @endif
        <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('finanzen.index') }}" class="text-gray-500 underline">Zurück</a>
    </div>

    @if($history->isNotEmpty())
        <section>
            <p class="mb-2 text-sm font-semibold text-gray-700">Änderungen</p>
            <ul class="space-y-1 text-xs text-gray-600">
                @foreach($history as $log)
                    <li>{{ $log->created_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }} · {{ $log->user?->name ?? 'System' }} · {{ \App\Models\ActivityLog::EVENTS[$log->event] ?? $log->event }}</li>
                @endforeach
            </ul>
        </section>
    @endif
</div>

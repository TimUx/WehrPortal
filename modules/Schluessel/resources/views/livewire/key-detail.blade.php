@php($input = 'mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
@php($card = 'space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm')
<div class="mx-auto max-w-3xl space-y-4">
    <a href="{{ route('schluessel.list') }}" class="text-sm text-gray-500 hover:text-gray-900">← Alle Schlüssel</a>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @foreach(['issue', 'due_on', 'signature'] as $f)@error($f)<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror @endforeach

    <section class="{{ $card }}">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-xl font-bold text-gray-900">{{ $item->name }}</h1>
                <p class="text-sm text-gray-500">{{ $item->code }} · {{ $item->kindLabel() }} · {{ $item->statusLabel() }}</p>
                @if($item->opensLabel())<p class="text-sm text-gray-700">Schließt: {{ $item->opensLabel() }}</p>@endif
                @if($item->place)<x-place-info :place="$item->place" />@endif
                @if($item->storage)<p class="text-sm text-gray-600">Aufbewahrung: {{ $item->storage }}</p>@endif
                @if($item->notes)<p class="mt-1 whitespace-pre-line text-xs text-gray-500">{{ $item->notes }}</p>@endif
            </div>
            <div class="flex flex-col items-center gap-1">
                <img src="{{ $qr }}" alt="QR-Code {{ $item->code }}" class="h-24 w-24">
                <a href="{{ route('schluessel.edit', $item) }}" class="text-xs text-fw-navy underline">Bearbeiten</a>
            </div>
        </div>
    </section>

    <section class="{{ $card }}">
        @if($item->current)
            <h2 class="text-sm font-semibold text-gray-900">Ausgegeben an {{ $item->current->user->name }}</h2>
            <p class="text-sm text-gray-600">seit {{ $item->current->issued_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}@if($item->current->due_on) · Rückgabe bis <span class="{{ $item->current->overdue() ? 'font-semibold text-red-700' : '' }}">{{ $item->current->due_on->format('d.m.Y') }}</span>@endif</p>
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="takeBack" class="rounded-lg bg-fw-navy px-3 py-1.5 text-sm font-semibold text-white">Zurückgenommen</button>
                <a href="{{ route('schluessel.receipt', $item->current) }}" target="_blank" rel="noopener" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700">Schlüsselquittung</a>
                @if($item->status !== 'lost')<button type="button" wire:click="reportLost" wire:confirm="Als verloren melden?" class="text-sm text-red-600 underline">Verloren</button>@endif
            </div>
        @elseif($item->status === 'available')
            <h2 class="text-sm font-semibold text-gray-900">Ausgeben</h2>
            <form wire:submit="issue" class="space-y-3">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label class="block text-sm text-gray-700">An<select wire:model="memberId" class="{{ $input }}"><option value="">– Kamerad wählen –</option>@foreach($people as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
                    <label class="block text-sm text-gray-700">Rückgabe bis (optional)<input type="date" wire:model="dueOn" class="{{ $input }}"></label>
                </div>
                <label class="block text-sm text-gray-700">Bemerkung (optional)<input type="text" wire:model="note" maxlength="300" placeholder="z. B. für den Jugendraum" class="{{ $input }}"></label>
                @include('partials.signature-pad', ['model' => 'signature', 'label' => 'Empfang bestätigt (Schlüsselquittung, optional)'])
                <button type="submit" class="rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white">Ausgeben</button>
            </form>
            <button type="button" wire:click="setStatus('retired')" wire:confirm="Schlüssel aussondern?" class="text-xs text-gray-500 underline">Aussondern</button>
        @else
            <p class="text-sm font-semibold {{ $item->status === 'lost' ? 'text-red-700' : 'text-gray-600' }}">{{ $item->statusLabel() }}</p>
            <button type="button" wire:click="setStatus('available')" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700">{{ $item->status === 'lost' ? 'Wiedergefunden' : 'Wieder in Gebrauch' }}</button>
        @endif
    </section>

    <section class="{{ $card }}">
        <h2 class="text-sm font-semibold text-gray-900">Verlauf</h2>
        <ul class="divide-y divide-gray-100 text-sm">
            @forelse($item->handovers as $h)
                <li class="flex flex-wrap items-center justify-between gap-2 py-1.5" wire:key="v-{{ $h->id }}">
                    <span><span class="font-medium text-gray-900">{{ $h->user->name }}</span>
                        <span class="block text-xs text-gray-500">{{ $h->issued_at->setTimezone('Europe/Berlin')->format('d.m.Y') }}{{ $h->returned_at ? ' – '.$h->returned_at->setTimezone('Europe/Berlin')->format('d.m.Y') : ' – heute' }}@if($h->issuer) · ausgegeben von {{ $h->issuer->name }}@endif @if($h->receiver) · zurück an {{ $h->receiver->name }}@endif @if($h->note) · {{ $h->note }}@endif</span></span>
                    <a href="{{ route('schluessel.receipt', $h) }}" target="_blank" rel="noopener" class="text-xs text-fw-navy underline">Quittung{{ $h->signature ? ' (unterschrieben)' : '' }}</a>
                </li>
            @empty
                <li class="py-1.5 text-gray-500">Noch nie ausgegeben.</li>
            @endforelse
        </ul>
    </section>
</div>

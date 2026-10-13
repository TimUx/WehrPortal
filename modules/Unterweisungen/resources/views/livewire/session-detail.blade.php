<div class="mx-auto max-w-3xl space-y-4">
    <div>
        <a href="{{ route('unterweisungen.overview') }}" class="text-sm text-gray-500 hover:text-gray-900">← Übersicht</a>
        <h1 class="text-xl font-bold text-gray-900">{{ $session->topic->label() }}</h1>
        <p class="text-sm text-gray-600">{{ $session->held_on->format('d.m.Y') }} · unterwiesen von {{ $session->instructor_name }}</p>
        @if($session->notes)<p class="mt-1 whitespace-pre-line text-sm text-gray-700">{{ $session->notes }}</p>@endif
        @foreach($files as $f)<a href="{{ route('unterweisungen.file', $f->id) }}" target="_blank" class="mr-3 text-sm text-fw-navy underline">📎 {{ $f->filename }}</a>@endforeach
        <a href="{{ route('unterweisungen.print', $session) }}" target="_blank" class="text-sm text-fw-navy underline">Nachweis drucken</a>
    </div>
    @if($signing)
        @php($current = $attendances->firstWhere('id', $signing))
        <section class="space-y-2 rounded-2xl border border-fw-navy bg-white p-4 shadow-sm" wire:key="sign-{{ $signing }}">
            <p class="text-sm font-semibold text-gray-900">{{ $current?->user?->name }} unterschreibt</p>
            @include('partials.signature-pad', ['model' => 'signature', 'label' => 'Teilgenommen und verstanden – '.$current?->user?->name])
            @error('signature')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <div class="flex gap-3"><button type="button" wire:click="sign" class="rounded-lg bg-fw-navy px-4 py-2 text-sm font-semibold text-white">Unterschrift speichern – weiter</button>
                <button type="button" wire:click="$set('signing', null)" class="text-sm text-gray-500 underline">Pause</button></div>
        </section>
    @endif
    <ul class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white text-sm shadow-sm">
        @foreach($attendances as $a)
            <li class="flex items-center justify-between gap-2 p-3">
                <span class="text-gray-900">{{ $a->user?->name }}</span>
                @if($a->signed_at)<span class="text-xs text-green-700">✓ unterschrieben {{ $a->signed_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}</span>
                @else<button type="button" wire:click="$set('signing', {{ $a->id }})" class="text-xs text-fw-navy underline">Unterschreiben lassen</button>@endif
            </li>
        @endforeach
    </ul>
    <p class="text-xs text-gray-500">Wer nicht da ist, unterschreibt später auf dem eigenen Handy unter „Meine Unterweisungen“.</p>
</div>

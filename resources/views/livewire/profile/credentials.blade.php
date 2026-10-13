<div class="space-y-4" wire:poll.300s>
    @forelse($cards as $card)
        <section aria-label="{{ $card['title'] }}">{!! $card['html'] !!}</section>
    @empty
        <p class="rounded-2xl border border-gray-100 bg-white p-4 text-sm text-gray-500">Für dich sind keine digitalen Nachweise hinterlegt.</p>
    @endforelse
    @if($cards)
        <section class="rounded-2xl border border-gray-100 bg-white p-4 text-center shadow-sm">
            <p class="text-sm font-semibold text-gray-900">Zum Prüfen vorzeigen</p>
            <img src="{{ $qr }}" alt="QR-Code zur Prüfung der Nachweise" class="mx-auto my-2 h-48 w-48">
            <p class="text-xs text-gray-500">Wer den Code scannt, sieht den Stand von jetzt – ohne Anmeldung. Der Code gilt bis {{ $until->format('H:i') }} Uhr und erneuert sich auf dieser Seite von selbst.</p>
        </section>
    @endif
</div>

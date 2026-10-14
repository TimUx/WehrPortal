<div class="mx-auto max-w-sm space-y-4" wire:poll.300s>
    @if($id)
        @include('personalverwaltung::credentials.id-card', ['id' => $id])
        <section class="rounded-2xl border border-gray-100 bg-white p-4 text-center shadow-sm">
            <p class="text-sm font-semibold text-gray-900">Zum Prüfen vorzeigen</p>
            <img src="{{ $qr }}" alt="QR-Code zur Prüfung des Dienstausweises" class="mx-auto my-2 h-48 w-48">
            <p class="text-xs text-gray-500">Wer den Code scannt, sieht den Stand von jetzt – ohne Anmeldung. Der Code gilt bis {{ $until->format('H:i') }} Uhr und erneuert sich auf dieser Seite von selbst.</p>
        </section>
    @else
        <p class="rounded-2xl border border-gray-100 bg-white p-4 text-sm text-gray-500">Der digitale Dienstausweis ist nicht (mehr) verfügbar.</p>
    @endif
</div>

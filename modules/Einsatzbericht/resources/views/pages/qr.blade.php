<x-layouts.app :title="'QR-Zugang '.$report->number">
    <div class="mx-auto max-w-md space-y-4 text-center">
        <a href="{{ route('einsatzbericht.show', $report) }}" class="block text-left text-sm text-gray-500 hover:text-gray-900">← Bericht</a>
        <h1 class="text-xl font-bold text-gray-900">QR-Zugang für externe Wehren</h1>
        @if($qr)
            <img src="{{ $qr }}" alt="QR-Code für den Einsatz" class="mx-auto w-full max-w-sm rounded-2xl bg-white p-3 shadow-sm">
            <p class="text-sm text-gray-600">Mit der Handykamera scannen. Gilt nur, solange der Einsatz läuft.</p>
            <p class="break-all text-xs text-gray-400">{{ $url }}</p>
        @else
            <p class="rounded-xl bg-gray-100 p-4 text-sm text-gray-700">Kein QR-Zugang aktiv. Im Bericht unter „Externe Wehren“ erzeugen.</p>
        @endif
    </div>
</x-layouts.app>

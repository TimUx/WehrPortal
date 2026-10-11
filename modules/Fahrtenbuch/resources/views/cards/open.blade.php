<a href="{{ route('fahrtenbuch.trip', ['fahrt' => $trip->id]) }}" class="block bg-white rounded-2xl border-2 border-amber-300 shadow-sm p-5 hover:border-fw-navy">
    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Fahrtenbuch</p>
    <p class="mt-1 font-semibold text-gray-900">Du bist mit {{ $trip->vehicle->name }} unterwegs</p>
    <p class="text-sm text-gray-600">seit {{ $trip->started_at->setTimezone('Europe/Berlin')->format('d.m. H:i') }} Uhr · <span class="underline">Fahrt beenden</span></p>
</a>

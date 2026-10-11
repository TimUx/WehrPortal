<x-layouts.app title="Fahrzeug zurückgeben">
    <div class="min-h-screen flex items-center justify-center bg-gray-50 px-4">
        <div class="w-full max-w-sm">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 text-center">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl mb-4"
                     style="background-color: {{ $booking->vehicle->color }}20">
                    <svg class="h-9 w-9" style="color: {{ $booking->vehicle->color }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>

                @if(session('returned'))
                <h1 class="text-xl font-semibold text-gray-900 mb-2">Danke!</h1>
                <p class="text-sm text-gray-500">
                    {{ $booking->vehicle->name }} ist zurückgemeldet und ab sofort wieder frei.
                </p>
                @elseif($booking->isRunning())
                <h1 class="text-xl font-semibold text-gray-900 mb-2">Fahrzeug zurückgeben</h1>
                <p class="text-sm text-gray-700 font-medium">{{ $booking->vehicle->name }} · {{ $booking->purpose }}</p>
                <p class="text-sm text-gray-500 mb-6">
                    gebucht bis {{ $booking->ends_at->setTimezone('Europe/Berlin')->format('H:i') }} Uhr.
                    Ist das Fahrzeug schon zurück, endet die Buchung jetzt und es ist sofort wieder frei.
                </p>

                <form method="POST" action="{{ request()->fullUrl() }}">
                    @csrf
                    <button type="submit"
                            class="w-full bg-fw-red text-white font-semibold py-3 px-4 rounded-xl hover:bg-fw-red-dark transition-colors">
                        Fahrzeug ist zurück
                    </button>
                </form>
                @else
                <h1 class="text-xl font-semibold text-gray-900 mb-2">Buchung bereits beendet</h1>
                <p class="text-sm text-gray-500">
                    Die Buchung von {{ $booking->vehicle->name }} („{{ $booking->purpose }}“) läuft nicht mehr. Es ist nichts mehr zu tun.
                </p>
                @endif

                <a href="{{ route('fahrzeugbuchung.calendar') }}" class="mt-4 inline-block text-sm text-gray-500 hover:text-gray-700">
                    Zum Kalender
                </a>
            </div>
        </div>
    </div>
</x-layouts.app>

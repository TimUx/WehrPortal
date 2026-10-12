<div class="space-y-4">
    @if(session('fahrtenbuch.flash'))<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ session('fahrtenbuch.flash') }}</p>@endif
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Fahrtenbuch</h1>
            <p class="text-sm text-gray-500">Fahrt beginnen, wenn du losfährst, und beenden, wenn du zurück bist – mit Kilometerstand. Die Fahrzeuge sind dieselben wie in Fahrzeugbuchung und Fahrzeugverwaltung.</p>
        </div>
        <div class="flex flex-wrap gap-2 text-sm">
            <a href="{{ route('fahrtenbuch.trip', ['nachtragen' => 1]) }}" class="rounded-lg border border-gray-300 bg-white px-3 py-2 font-semibold text-gray-700">Fahrt nachtragen</a>
        </div>
    </div>

    @foreach($mine as $trip)
        <a href="{{ route('fahrtenbuch.trip', ['fahrt' => $trip->id]) }}" class="flex items-center justify-between gap-3 rounded-2xl border-2 border-amber-300 bg-amber-50 p-4">
            <span><span class="block font-semibold text-gray-900">Du bist mit {{ $trip->vehicle->name }} unterwegs</span><span class="text-sm text-gray-600">seit {{ $trip->started_at->setTimezone('Europe/Berlin')->format('d.m. H:i') }} Uhr, ab {{ number_format($trip->start_km, 0, ',', '.') }} km</span></span>
            <span class="rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white">Fahrt beenden</span>
        </a>
    @endforeach

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        @forelse($vehicles as $vehicle)
            @php($trip = $open[$vehicle->id] ?? null)
            <div class="space-y-2 rounded-2xl border bg-white p-4 shadow-sm {{ $trip ? 'border-amber-300' : 'border-gray-100' }}" wire:key="v-{{ $vehicle->id }}">
                <div class="flex items-start justify-between gap-2">
                    <a href="{{ route('fahrtenbuch.vehicle', $vehicle) }}" class="text-lg font-bold text-gray-900 hover:underline">{{ $vehicle->name }}</a>
                    <span class="h-3 w-3 shrink-0 rounded-full" style="background-color: {{ $vehicle->color }}"></span>
                </div>
                @if($trip)
                    <p class="text-sm text-amber-900">Unterwegs: {{ $trip->driver->name }} seit {{ $trip->started_at->setTimezone('Europe/Berlin')->format('H:i') }} Uhr</p>
                @else
                    <p class="text-sm text-gray-600">Frei · {{ $km[$vehicle->id] !== null ? number_format($km[$vehicle->id], 0, ',', '.').' km' : 'noch kein Kilometerstand' }}</p>
                @endif
                <div class="flex flex-wrap gap-2">
                    @if($trip)
                        <a href="{{ route('fahrtenbuch.trip', ['fahrt' => $trip->id]) }}" class="flex-1 rounded-lg bg-fw-red px-4 py-2 text-sm text-center font-semibold text-white">Fahrt beenden</a>
                    @else
                        <a href="{{ route('fahrtenbuch.trip', ['fahrzeug' => $vehicle->id]) }}" class="flex-1 rounded-lg bg-fw-navy px-4 py-2 text-sm text-center font-semibold text-white">Fahrt beginnen</a>
                    @endif
                    <a href="{{ route('fahrtenbuch.fuel', ['fahrzeug' => $vehicle->id]) }}" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700">Tanken</a>
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500">Keine Fahrzeuge. Fahrzeuge werden in der Fahrzeugverwaltung oder im Admin-Bereich der Fahrzeugbuchung angelegt.</p>
        @endforelse
    </div>

    @if($recent->isNotEmpty())
        <section class="space-y-2">
            <p class="text-sm font-semibold text-gray-900">Letzte Fahrten</p>
            <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
                @foreach($recent as $t)
                    <a href="{{ route('fahrtenbuch.vehicle', $t->vehicle_id) }}" class="flex items-center justify-between gap-3 p-3 text-sm hover:bg-gray-50" wire:key="r-{{ $t->id }}">
                        <span class="min-w-0"><span class="block truncate text-gray-900">{{ $t->vehicle->name }}: {{ $t->origin }} → {{ $t->destination }}</span><span class="text-xs text-gray-500">{{ $t->started_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }} · {{ $t->purposeLabel() }} · {{ $t->driver->name }}</span></span>
                        <span class="shrink-0 font-semibold text-gray-900">{{ number_format((int) $t->distance(), 0, ',', '.') }} km</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
</div>

{{-- Fahrtenbuch in the Fahrzeugprofil of the Fahrzeugverwaltung --}}
@php($logbook = app(\Modules\Fahrtenbuch\src\Services\LogbookService::class))
@php($year = now('Europe/Berlin')->year)
@php($trips = $logbook->yearTrips($vehicle, $year))
@php($consumption = $logbook->consumption($vehicle, $year))
<section class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
    <div class="flex items-center justify-between gap-2">
        <p class="text-sm font-semibold text-gray-900">Fahrtenbuch {{ $year }}</p>
        <a href="{{ route('fahrtenbuch.vehicle', $vehicle) }}" class="text-xs text-fw-navy underline">Fahrtenbuch öffnen</a>
    </div>
    <p class="text-sm text-gray-700">{{ $trips->count() }} Fahrten, {{ number_format((int) $trips->sum(fn ($t) => $t->distance()), 0, ',', '.') }} km
        @if($consumption->sum('km') > 0) · Ø {{ str_replace('.', ',', (string) round($consumption->sum('liters') / $consumption->sum('km') * 100, 1)) }} l/100 km @endif</p>
    @foreach($trips->sortByDesc('started_at')->take(5) as $t)
        <p class="text-xs text-gray-600">{{ $t->started_at->setTimezone('Europe/Berlin')->format('d.m. H:i') }} · {{ $t->origin }} → {{ $t->destination ?? 'unterwegs' }} · {{ $t->purposeLabel() }} · {{ $t->driver->name }}{{ $t->distance() !== null ? ' · '.$t->distance().' km' : '' }}</p>
    @endforeach
</section>

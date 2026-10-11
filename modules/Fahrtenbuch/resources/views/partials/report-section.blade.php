{{-- Trips of this Einsatz in the Einsatzbericht --}}
@php($trips = \Modules\Fahrtenbuch\src\Models\Trip::valid()->with(['vehicle', 'driver'])->where('report_id', $report->id)->orderBy('started_at')->get())
@if($trips->isNotEmpty())
    <section class="mx-auto mt-4 max-w-4xl space-y-1 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">Fahrtenbuch</p>
        @foreach($trips as $t)
            <p class="text-sm text-gray-700">{{ $t->vehicle->name }}: {{ $t->started_at->setTimezone('Europe/Berlin')->format('H:i') }}–{{ $t->ended_at?->setTimezone('Europe/Berlin')->format('H:i') ?? '…' }} · {{ $t->driver->name }} · {{ $t->distance() !== null ? $t->distance().' km' : 'unterwegs' }}</p>
        @endforeach
    </section>
@endif

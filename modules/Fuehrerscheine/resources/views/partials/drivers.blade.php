{{-- Fahrzeuge: who may drive this vehicle, from the checked Führerscheine --}}
@php($drv = app(\App\Services\DriverRegistry::class)->drivers($vehicleId))
@if($drv)
    <section class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <h2 class="text-sm font-semibold text-gray-900">Wer darf fahren? <span class="font-normal text-gray-500">({{ $drv['required'] }})</span></h2>
        <p class="text-sm text-gray-700">{{ $drv['users']->pluck('name')->implode(', ') ?: 'Niemand mit geprüfter, gültiger Fahrerlaubnis.' }}</p>
        <p class="text-xs text-gray-500">Aus den geprüften Führerscheinen ({{ $drv['users']->count() }}).</p>
    </section>
@endif

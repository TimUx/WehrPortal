{{-- Einsatzbericht-Ergänzungen: Sollbesatzung-Hinweise und Zusatzangaben (Kostenpflicht, Personen, Verdienstausfall) --}}
@php
    $ebpCard = $card ?? 'rounded-xl border border-gray-200 bg-white p-4 shadow-sm';
    $ebpVehicles = $report->vehicles->reject->is_standby->filter(fn ($v) => $v->crew->isNotEmpty() || $v->vehicle_id);
    $ebpProfiles = [];
    if ($ebpVehicles->pluck('vehicle_id')->filter()->isNotEmpty()
        && app(\App\Services\ModuleRegistry::class)->isActive('fahrzeuge')
        && class_exists(\Modules\Fahrzeuge\src\Models\Profile::class)) {
        $ebpProfiles = \Modules\Fahrzeuge\src\Models\Profile::whereIn('vehicle_id', $ebpVehicles->pluck('vehicle_id')->filter())->pluck('crew', 'vehicle_id')->all();
    }
    $ebpTones = ['gray' => 'text-gray-600', 'amber' => 'text-amber-700', 'red' => 'text-red-700', 'green' => 'text-green-700'];
@endphp
@if($ebpVehicles->isNotEmpty())
    <section class="{{ $ebpCard }}" aria-label="Besatzung im Vergleich zur Sollstärke">
        <h2 class="text-base font-semibold text-fw-navy">Besatzung im Vergleich zur Sollstärke</h2>
        <p class="mt-1 text-xs text-gray-500">Nur ein Hinweis: Nicht jeder Platz muss besetzt sein, Atemschutz ist je Person freiwillig.</p>
        <ul class="mt-2 space-y-1 text-sm">
            @foreach($ebpVehicles as $ebpVehicle)
                @php
                    $ebpExpected = \Extensions\EinsatzberichtPlus\Support\CrewSize::expected($ebpProfiles[$ebpVehicle->vehicle_id] ?? null);
                    $ebpCheck = \Extensions\EinsatzberichtPlus\Support\CrewSize::check($ebpVehicle->crew->count(), $ebpExpected);
                @endphp
                <li data-crew-check="{{ $ebpCheck['state'] }}"><span class="font-medium">{{ $ebpVehicle->name }}:</span> <span class="{{ $ebpTones[$ebpCheck['tone']] }}">{{ $ebpCheck['text'] }}</span></li>
            @endforeach
        </ul>
    </section>
@endif
<livewire:ebp.extras :report-id="$report->id" :key="'ebp-'.$report->id.'-'.$report->vehicles->flatMap->crew->pluck('user_id')->unique()->sort()->implode('-')" />

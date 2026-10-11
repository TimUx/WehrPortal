<?php

namespace Modules\Fahrtenbuch\src\Livewire;

use Livewire\Component;
use Modules\Fahrtenbuch\src\Models\Trip;
use Modules\Fahrtenbuch\src\Services\LogbookService;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

/** The vehicles: who is out with which one, the last Kilometerstand, start or end a trip; also the kiosk view */
class Overview extends Component
{
    public bool $kiosk = false;

    public function mount(): void
    {
        $this->kiosk = request()->boolean('kiosk');
    }

    public function render(LogbookService $logbook)
    {
        $vehicles = Vehicle::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
        $open = Trip::open()->with('driver')->whereIn('vehicle_id', $vehicles->pluck('id'))->get()->keyBy('vehicle_id');

        return view('fahrtenbuch::livewire.overview', [
            'vehicles' => $vehicles,
            'open' => $open,
            'km' => $vehicles->mapWithKeys(fn (Vehicle $v) => [$v->id => $logbook->lastKm($v)]),
            'mine' => Trip::open()->with('vehicle')->where('driver_id', auth()->id())->get(),
            'recent' => $this->kiosk ? collect() : Trip::valid()->with(['vehicle', 'driver'])->whereNotNull('ended_at')->latest('ended_at')->limit(10)->get(),
        ]);
    }
}

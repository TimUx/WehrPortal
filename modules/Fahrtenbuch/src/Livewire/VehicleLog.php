<?php

namespace Modules\Fahrtenbuch\src\Livewire;

use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Fahrtenbuch\src\Models\Fueling;
use Modules\Fahrtenbuch\src\Models\Trip;
use Modules\Fahrtenbuch\src\Services\LogbookService;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

/** The Fahrtenbuch of one vehicle and year: trips with gaps, fuel stops, consumption, export */
class VehicleLog extends Component
{
    #[Locked]
    public Vehicle $vehicle;

    #[Url(as: 'jahr')]
    public ?int $year = null;

    public ?string $flash = null;

    public function mount(): void
    {
        $this->year ??= now('Europe/Berlin')->year;
        $this->flash = session('fahrtenbuch.flash');
    }

    public function render(LogbookService $logbook)
    {
        abort_unless(LogbookService::canAccessVehicle(auth()->user(), $this->vehicle), 404);
        $trips = $logbook->yearTrips($this->vehicle, $this->year, withVoided: true);
        $valid = $trips->whereNull('voided_at');
        $fuel = Fueling::with('user')->where('vehicle_id', $this->vehicle->id)->get()
            ->filter(fn (Fueling $f) => $f->fueled_at->setTimezone('Europe/Berlin')->year === $this->year)->sortByDesc('fueled_at')->values();
        $consumption = $logbook->consumption($this->vehicle, $this->year);

        return view('fahrtenbuch::livewire.vehicle-log', [
            'trips' => $trips->sortByDesc('started_at')->values(),
            'gaps' => LogbookService::gaps($trips),
            'km' => (int) $valid->sum(fn (Trip $t) => $t->distance()),
            'fuel' => $fuel,
            'consumption' => $consumption,
            'per100' => $consumption->sum('km') > 0 ? round($consumption->sum('liters') / $consumption->sum('km') * 100, 1) : null,
            'lastKm' => $logbook->lastKm($this->vehicle),
            'years' => Trip::where('vehicle_id', $this->vehicle->id)->selectRaw("distinct extract(year from started_at at time zone 'Europe/Berlin')::int as y")->pluck('y')
                ->push(now('Europe/Berlin')->year)->unique()->sortDesc()->values(),
            'canManage' => LogbookService::canManageVehicle(auth()->user(), $this->vehicle),
        ]);
    }
}

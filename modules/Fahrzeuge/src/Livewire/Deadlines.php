<?php

namespace Modules\Fahrzeuge\src\Livewire;

use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Fahrzeuge\src\Services\FleetService;

/** The Fristenliste: deadlines of all vehicles, overdue / next 90 days / all, printable */
class Deadlines extends Component
{
    #[Url(as: 'filter')]
    public string $filter = 'upcoming';

    #[Url(as: 'fahrzeug')]
    public string $vehicleId = '';

    public function render(FleetService $fleet)
    {
        $filter = in_array($this->filter, ['overdue', 'upcoming', 'all'], true) ? $this->filter : 'upcoming';

        return view('fahrzeuge::livewire.deadlines', [
            'deadlines' => $fleet->deadlines($filter, $this->vehicleId !== '' ? (int) $this->vehicleId : null, auth()->user()),
            'vehicles' => FleetService::vehiclesFor(auth()->user())->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'canManage' => FleetService::canManage(auth()->user()),
        ]);
    }
}

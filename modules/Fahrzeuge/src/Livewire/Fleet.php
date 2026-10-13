<?php

namespace Modules\Fahrzeuge\src\Livewire;

use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeuge\src\Models\Deadline;
use Modules\Fahrzeuge\src\Models\Profile;
use Modules\Fahrzeuge\src\Services\FleetService;

/** All vehicles with status at a glance, who is out at an Einsatz, and the deadlines coming up */
class Fleet extends Component
{
    #[Url(as: 'art')]
    public string $category = '';

    #[Url(as: 'ausser-dienst')]
    public bool $showDecommissioned = false;

    public function render(FleetService $fleet)
    {
        $vehicles = Vehicle::query()->when(! $this->showDecommissioned, fn ($q) => $q->where('is_active', true))->orderBy('sort_order')->orderBy('name')->get();
        $profiles = Profile::whereIn('vehicle_id', $vehicles->pluck('id'))->get()->keyBy('vehicle_id');
        if ($this->category !== '') {
            $vehicles = $vehicles->filter(fn ($v) => ($profiles[$v->id]?->category ?? '') === $this->category)->values();
        }
        $limit = FleetService::today()->addDays(30)->toDateString();

        return view('fahrzeuge::livewire.fleet', [
            'vehicles' => $vehicles,
            'profiles' => $profiles,
            'states' => $fleet->states($vehicles),
            'assignments' => $fleet->runningAssignments(),
            'defects' => $fleet->openDefects($vehicles->pluck('id')->all()),
            'deadlines' => Deadline::active()->with('vehicle')->whereHas('vehicle', fn ($q) => $q->where('is_active', true))
                ->where('due_on', '<=', $limit)->orderBy('due_on')->get(),
            'canManage' => FleetService::canManage(auth()->user()),
        ]);
    }
}

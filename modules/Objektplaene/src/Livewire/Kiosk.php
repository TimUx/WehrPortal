<?php

namespace Modules\Objektplaene\src\Livewire;

use Livewire\Component;
use Modules\Objektplaene\src\Models\PlanObject;
use Modules\Objektplaene\src\Services\ObjectPlanService;

/**
 * For the tablet in the ELW or the Gerätehaus: the plans of the running Einsätze come up by
 * themselves; otherwise search. Updates itself every 20 seconds.
 */
class Kiosk extends Component
{
    public string $search = '';

    public function render(ObjectPlanService $plans)
    {
        $term = trim($this->search);

        return view('objektplaene::livewire.kiosk', [
            'running' => $plans->running(),
            'found' => $term === '' ? collect() : PlanObject::active()->with('documents.current')
                ->where(fn ($w) => $w->where('name', 'ilike', '%'.$term.'%')->orWhere('address', 'ilike', '%'.$term.'%'))
                ->orderBy('name')->limit(12)->get(),
        ]);
    }
}

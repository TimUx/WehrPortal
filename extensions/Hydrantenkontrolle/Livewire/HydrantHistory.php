<?php

namespace Extensions\Hydrantenkontrolle\Livewire;

use Extensions\Hydrantenkontrolle\Models\Hydrant;
use Extensions\Hydrantenkontrolle\Services\InspectionService;
use Extensions\Hydrantenkontrolle\Support\Checklist;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

class HydrantHistory extends Component
{
    #[Locked]
    public int $hydrantId;

    public function mount(Hydrant $hydrant): void
    {
        Gate::authorize('manage-hydrantenkontrolle');
        $this->hydrantId = $hydrant->id;
    }

    public function render(InspectionService $service)
    {
        Gate::authorize('manage-hydrantenkontrolle');
        $hydrant = Hydrant::findOrFail($this->hydrantId);

        return view('hydrantenkontrolle::livewire.hydrant-history', [
            'hydrant' => $hydrant,
            'inspections' => $service->inspectionHistory(auth()->user(), $hydrant),
            'defects' => Checklist::DEFECTS,
        ]);
    }
}

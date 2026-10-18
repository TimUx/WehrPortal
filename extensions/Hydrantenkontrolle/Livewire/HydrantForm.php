<?php

namespace Extensions\Hydrantenkontrolle\Livewire;

use Extensions\Hydrantenkontrolle\Models\Hydrant;
use Extensions\Hydrantenkontrolle\Services\InspectionService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

class HydrantForm extends Component
{
    #[Locked]
    public ?int $hydrantId = null;

    #[Locked]
    public ?int $version = null;

    public array $form = [
        'number' => '', 'street' => '', 'house_number' => '', 'location_description' => '',
        'latitude' => '', 'longitude' => '', 'active' => true,
    ];

    public function mount(?Hydrant $hydrant = null): void
    {
        Gate::authorize('manage-hydrantenkontrolle');
        if ($hydrant?->exists) {
            $hydrant = Hydrant::findOrFail($hydrant->id);
            $this->hydrantId = $hydrant->id;
            $this->version = $hydrant->version;
            foreach (array_keys($this->form) as $key) {
                $this->form[$key] = $hydrant->{$key} ?? '';
            }
        }
    }

    public function save(InspectionService $service)
    {
        $service->saveHydrant(auth()->user(), $this->form, $this->hydrantId ? Hydrant::findOrFail($this->hydrantId) : null, $this->version);
        session()->flash('flash', 'Hydrant gespeichert. Bereits angelegte Jahresdaten bleiben unverändert.');

        return $this->redirectRoute('hydrantenkontrolle.hydrants');
    }

    public function render()
    {
        Gate::authorize('manage-hydrantenkontrolle');
        if ($this->hydrantId) {
            Hydrant::findOrFail($this->hydrantId);
        }

        return view('hydrantenkontrolle::livewire.hydrant-form');
    }
}

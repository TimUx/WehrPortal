<?php

namespace Modules\Objektplaene\src\Livewire;

use App\Services\Geocoder;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Objektplaene\src\Models\PlanObject;
use Modules\Objektplaene\src\Services\ObjectPlanService;

/** Create or change an object; the position comes from the map, GPS or the address */
class ObjectForm extends Component
{
    public ?PlanObject $object = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(?PlanObject $object = null): void
    {
        $this->object = $object?->exists ? $object : null;
        $o = $this->object;
        $this->form = [
            'name' => (string) $o?->name, 'object_type' => $o?->object_type ?? 'sonstiges', 'hazard_class' => $o?->hazard_class ?? 'normal',
            'address' => (string) $o?->address, 'latitude' => $o?->latitude !== null ? (string) $o->latitude : '', 'longitude' => $o?->longitude !== null ? (string) $o->longitude : '',
            'radius_m' => (string) ($o?->radius_m ?? 150), 'has_bma' => (bool) $o?->has_bma, 'has_fsd' => (bool) $o?->has_fsd, 'has_hazardous_materials' => (bool) $o?->has_hazardous_materials,
            'access' => (string) $o?->access, 'hazards' => (string) $o?->hazards, 'contacts' => (string) $o?->contacts, 'notes' => (string) $o?->notes,
            'review_interval_months' => (string) ($o ? ($o->review_interval_months ?? '') : 24), 'is_active' => $o?->is_active ?? true,
        ];
    }

    /** "Position aus Adresse": Google geocoding with the stored key, when there is one */
    public function locateAddress(Geocoder $geocoder): void
    {
        $this->resetErrorBag('latitude');
        $point = $geocoder->locate(trim((string) $this->form['address']));
        if (! $point) {
            $this->addError('latitude', $geocoder->available() ? 'Zu dieser Adresse wurde keine Position gefunden. Bitte auf die Karte tippen.' : 'Ohne Google-Schlüssel (Admin → Dienste) bitte die Position auf der Karte setzen.');

            return;
        }
        $this->form['latitude'] = (string) round($point['lat'], 7);
        $this->form['longitude'] = (string) round($point['lng'], 7);
        $this->dispatch('object-position', lat: $point['lat'], lng: $point['lng']);
    }

    public function save(ObjectPlanService $plans)
    {
        $this->resetErrorBag();
        try {
            $object = $plans->saveObject(auth()->user(), $this->object, $this->form);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return null;
        }

        return redirect()->route('objektplaene.object', $object);
    }

    public function render(Geocoder $geocoder)
    {
        return view('objektplaene::livewire.object-form', ['canGeocode' => $geocoder->available()]);
    }
}

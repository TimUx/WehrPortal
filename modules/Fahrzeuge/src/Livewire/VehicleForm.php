<?php

namespace Modules\Fahrzeuge\src\Livewire;

use App\Support\Money;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Ausruestung\src\Models\Location;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeuge\src\Models\Profile;
use Modules\Fahrzeuge\src\Services\FleetService;

/** Create or change a vehicle: the shared fields (name, colour) and the Fahrzeugprofil */
class VehicleForm extends Component
{
    use WithFileUploads;

    public ?Vehicle $vehicle = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var list<string> */
    public array $features = [];

    public string $newFeature = '';

    public ?TemporaryUploadedFile $photo = null;

    public function mount(?Vehicle $vehicle = null): void
    {
        $this->vehicle = $vehicle?->exists ? $vehicle : null;
        $profile = $this->vehicle ? Profile::firstOrNew(['vehicle_id' => $this->vehicle->id]) : new Profile;
        $v = $this->vehicle;
        $this->form = [
            'name' => (string) $v?->name, 'short_name' => (string) $v?->short_name, 'color' => $v?->color ?? '#3b82f6', 'description' => (string) $v?->description,
            'sort_order' => (string) ($v?->sort_order ?? 0),
            ...collect(['call_sign', 'license_plate', 'category', 'vehicle_type', 'chassis', 'body_builder', 'year_built', 'vin', 'gross_weight_kg', 'crew',
                'license_class', 'water_liters', 'foam_liters', 'pump', 'notes', 'mileage_km', 'location_id', 'supplier', 'funding'])
                ->mapWithKeys(fn ($f) => [$f => (string) ($profile->{$f} ?? '')])->all(),
            'first_registration' => (string) $profile->first_registration?->toDateString(),
            'purchased_on' => (string) $profile->purchased_on?->toDateString(), 'warranty_until' => (string) $profile->warranty_until?->toDateString(),
            'purchase_price' => $profile->purchase_price_cents !== null ? Money::input($profile->purchase_price_cents) : '',
            'operating_hours' => $profile->operating_hours !== null ? str_replace('.', ',', (string) $profile->operating_hours) : '',
        ];
        $this->features = $profile->features ?? [];
    }

    public function addFeature(): void
    {
        foreach (preg_split('/(?:\R|[,;])+/', $this->newFeature) as $feature) {
            if (trim($feature) !== '' && ! in_array(trim($feature), $this->features, true)) {
                $this->features[] = mb_substr(trim($feature), 0, 100);
            }
        }
        $this->newFeature = '';
    }

    public function removeFeature(int $index): void
    {
        unset($this->features[$index]);
        $this->features = array_values($this->features);
    }

    public function save(FleetService $fleet)
    {
        $this->resetErrorBag();
        $this->validate(['photo' => ['nullable', 'image', 'max:10240']], ['photo.image' => 'Bitte ein Foto hochladen.', 'photo.max' => 'Das Foto darf höchstens 10 MB groß sein.']);

        try {
            $vehicle = $fleet->saveVehicle(auth()->user(), $this->vehicle, [...$this->form, 'features' => $this->features], $this->photo);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return null;
        }

        session()->flash('fahrzeuge.flash', $this->vehicle ? 'Gespeichert.' : 'Fahrzeug angelegt. Es steht jetzt auch in der Fahrzeugbuchung und im Einsatzbericht zur Wahl.');

        return redirect()->route('fahrzeuge.show', $vehicle);
    }

    public function render()
    {
        return view('fahrzeuge::livewire.vehicle-form', [
            'locations' => Location::where('kind', 'vehicle')->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
        ]);
    }
}

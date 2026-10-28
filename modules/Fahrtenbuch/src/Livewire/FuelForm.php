<?php

namespace Modules\Fahrtenbuch\src\Livewire;

use App\Support\FileResponse;
use App\Support\Money;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Fahrtenbuch\src\Models\Fueling;
use Modules\Fahrtenbuch\src\Services\LogbookService;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

/** A fuel stop: vehicle, Kilometerstand, litres, cost, full tank, receipt */
class FuelForm extends Component
{
    use WithFileUploads;

    public string $vehicleId = '';

    public string $fueledAt = '';

    public string $km = '';

    public string $liters = '';

    public string $cost = '';

    public string $fuel = 'diesel';

    public bool $fullTank = true;

    public string $station = '';

    public string $notes = '';

    public ?TemporaryUploadedFile $receipt = null;

    public function mount(LogbookService $logbook): void
    {
        $this->fueledAt = now('Europe/Berlin')->format('Y-m-d\TH:i');
        $this->vehicleId = (string) (Vehicle::where('is_active', true)->find((int) request('fahrzeug'))?->id ?? '');
        $this->updatedVehicleId($logbook);
    }

    public function updatedVehicleId(LogbookService $logbook): void
    {
        $vehicle = $this->vehicleId !== '' ? Vehicle::find((int) $this->vehicleId) : null;
        if ($vehicle) {
            $this->km = (string) ($logbook->lastKm($vehicle) ?? '');
            $this->fuel = Fueling::where('vehicle_id', $vehicle->id)->where('fuel', '!=', 'adblue')->latest('fueled_at')->value('fuel') ?? 'diesel';
        }
    }

    public function save(LogbookService $logbook)
    {
        $this->resetErrorBag();
        $extensions = array_diff(FileResponse::allowedExtensions(false), ['gif']);
        $this->validate(['receipt' => ['nullable', 'file', 'max:10240', 'extensions:'.implode(',', $extensions)]], ['receipt.*' => 'Bitte ein Foto oder eine PDF bis 10 MB.']);
        $cost = trim($this->cost) === '' ? null : Money::parse($this->cost);
        if (trim($this->cost) !== '' && $cost === null) {
            $this->addError('cost', 'Bitte den Betrag angeben, z. B. 112,40.');

            return null;
        }

        try {
            $fueling = $logbook->addFueling(auth()->user(), ['vehicle_id' => $this->vehicleId, 'fueled_at' => $this->fueledAt, 'km' => $this->km, 'liters' => $this->liters,
                'cost_cents' => $cost, 'fuel' => $this->fuel, 'full_tank' => $this->fullTank, 'station' => $this->station, 'notes' => $this->notes], $this->receipt);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return null;
        }

        session()->flash('fahrtenbuch.flash', 'Tanken eingetragen: '.$fueling->liters().'.');

        return redirect()->route('fahrtenbuch.vehicle', $fueling->vehicle_id);
    }

    public function render()
    {
        $selectedVehicle = $this->vehicleId !== '' ? Vehicle::find((int) $this->vehicleId) : null;

        return view('fahrtenbuch::livewire.fuel-form', [
            'vehicles' => Vehicle::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'selectedVehicle' => $selectedVehicle,
        ]);
    }
}

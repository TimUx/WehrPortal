<?php

namespace Modules\Maengelmanagement\src\Livewire;

use App\Services\ModuleRegistry;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Maengelmanagement\src\Enums\Urgency;
use Modules\Maengelmanagement\src\Models\Device;
use Modules\Maengelmanagement\src\Services\DefectPhotoService;
use Modules\Maengelmanagement\src\Services\DefectService;

/** Report a defect in about 30 seconds; a QR scan preselects the device */
class ReportForm extends Component
{
    use WithFileUploads;

    #[Url(as: 'objekt')]
    public string $deviceCode = '';

    public ?int $deviceId = null;

    /** A vehicle of the shared list; preselected from the Fahrzeugakte (?fahrzeug=) */
    #[Url(as: 'fahrzeug')]
    public ?int $vehicleId = null;

    /** A Gerät of the Ausrüstungsverwaltung, preselected by its code (?geraet=AR-0001) */
    #[Url(as: 'geraet')]
    public string $equipmentCode = '';

    public ?int $equipmentId = null;

    public string $location = '';

    public string $title = '';

    public string $description = '';

    public string $urgency = 'medium';

    /** @var array<int, TemporaryUploadedFile> */
    public array $photos = [];

    public function mount(): void
    {
        if (filled($this->deviceCode)) {
            $this->deviceId = Device::active()->where('code', $this->deviceCode)->value('id');
        }
        if (filled($this->equipmentCode) && $this->ausruestung()) {
            $this->equipmentId = Equipment::findByScan($this->equipmentCode)?->id;
        }
    }

    public function removePhoto(int $index): void
    {
        unset($this->photos[$index]);
        $this->photos = array_values($this->photos);
    }

    public function save(DefectService $service)
    {
        $this->validate([
            'deviceId' => ['nullable', Rule::exists('mm_devices', 'id')->where('is_active', true)],
            'vehicleId' => ['nullable', Rule::exists('vehicles', 'id')->where('is_active', true)],
            'equipmentId' => ['nullable', Rule::exists('ar_equipment', 'id')],
            'location' => ['nullable', 'string', 'max:150'],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'urgency' => ['required', Rule::enum(Urgency::class)],
            'photos' => ['array', 'max:'.DefectPhotoService::MAX_PHOTOS],
            'photos.*' => ['image', 'max:12288'],
        ], [
            'title.required' => 'Bitte beschreibe den Mangel in einem kurzen Titel.',
            'photos.max' => 'Es sind höchstens '.DefectPhotoService::MAX_PHOTOS.' Fotos möglich.',
            'photos.*.image' => 'Bitte nur Bilder (JPG, PNG oder WebP) hochladen.',
            'photos.*.max' => 'Ein Foto ist größer als 12 MB.',
        ]);

        if (blank($this->deviceId) && blank($this->vehicleId) && blank($this->equipmentId) && blank($this->location)) {
            $this->addError('deviceId', 'Wähle ein Objekt, Fahrzeug oder Gerät aus oder gib an, wo der Mangel ist.');

            return;
        }

        $defect = $service->report(auth()->user(), [
            'device_id' => $this->deviceId ?: null,
            'vehicle_id' => $this->deviceId ? null : ($this->vehicleId ?: null),
            'equipment_id' => $this->deviceId || $this->vehicleId ? null : ($this->equipmentId ?: null),
            'location' => $this->deviceId || $this->vehicleId || $this->equipmentId ? null : trim($this->location),
            'title' => trim($this->title),
            'description' => trim($this->description) ?: null,
            'urgency' => $this->urgency,
        ], collect($this->photos)->map(fn ($photo) => $photo->getRealPath())->all());

        session()->flash('success', 'Danke! Der Mangel ist gemeldet.');

        return redirect()->route('maengelmanagement.show', $defect);
    }

    private function ausruestung(): bool
    {
        return app(ModuleRegistry::class)->isActive('ausruestung');
    }

    public function render()
    {
        return view('maengelmanagement::livewire.report-form', [
            'devices' => Device::active()->orderBy('name')->get(),
            'vehicles' => Vehicle::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'equipment' => $this->ausruestung()
                ? Equipment::inService()->orderBy('name')->get(['id', 'code', 'name'])
                : collect(),
            'urgencies' => Urgency::cases(),
        ]);
    }
}

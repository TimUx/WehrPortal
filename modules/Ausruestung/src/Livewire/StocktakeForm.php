<?php

namespace Modules\Ausruestung\src\Livewire;

use App\Models\User;
use Extensions\Locations\Models\Location as Standort;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Ausruestung\src\Models\Location;
use Modules\Ausruestung\src\Models\Stocktake;
use Modules\Ausruestung\src\Services\EquipmentService;
use Modules\Ausruestung\src\Services\StocktakeService;

/**
 * Inventur for the Gerätewarte: pick a room or vehicle, then scan (barcode or RFID reader, NFC on
 * the phone, or typing) or tick what is there. Missing, found elsewhere and unknown codes show
 * up live; "Inventur abschließen" saves it.
 */
class StocktakeForm extends Component
{
    #[Url(as: 'ort')]
    public string $locationId = '';

    #[Url(as: 'standort')]
    public string $standortId = '';

    public string $code = '';

    /** @var list<int> */
    public array $found = [];

    /** @var list<string> */
    public array $unknown = [];

    public bool $moveHere = true;

    public string $notes = '';

    public ?string $last = null;

    public ?int $savedId = null;

    public function mount(): void
    {
        $user = auth()->user();
        $scope = EquipmentService::equipmentManagementScope($user);
        if ($scope !== null) {
            $this->standortId = (string) ($scope[0] ?? '');
        }
    }

    public function updatedLocationId(): void
    {
        $this->reset('found', 'unknown', 'last', 'savedId', 'notes');
    }

    public function updatedStandortId(): void
    {
        $this->reset('locationId', 'found', 'unknown', 'last', 'savedId', 'notes');
    }

    public function scan(): void
    {
        $code = trim($this->code);
        $this->code = '';
        if ($code === '' || $this->locationId === '') {
            return;
        }
        $item = Equipment::findByScan($code);
        if (! $item) {
            if (! in_array($code, $this->unknown, true)) {
                $this->unknown[] = $code;
            }
            $this->last = '„'.$code.'“ ist unbekannt.';

            return;
        }
        /** @var User $user */
        $user = auth()->user();
        $scope = EquipmentService::equipmentManagementScope($user);
        $manageable = EquipmentService::managedEquipmentFor($user)->whereKey($item->id);
        if ($scope !== null && $this->standortId !== '') {
            $manageable->where('ext_location_id', (int) $this->standortId);
        }
        if (! $manageable->exists()) {
            if (! in_array($code, $this->unknown, true)) {
                $this->unknown[] = $code;
            }
            $this->last = '„'.$code.'“ ist unbekannt.';

            return;
        }
        $this->last = in_array($item->id, $this->found, true) ? $item->name.' war schon erfasst.' : '✓ '.$item->name.' ('.$item->code.')';
        $this->found = array_values(array_unique([...$this->found, $item->id]));
    }

    public function toggle(int $id): void
    {
        $this->found = in_array($id, $this->found, true) ? array_values(array_diff($this->found, [$id])) : [...$this->found, $id];
    }

    public function finish(StocktakeService $service): void
    {
        $user = auth()->user();
        $scope = EquipmentService::equipmentManagementScope($user);
        $equipment = EquipmentService::managedEquipmentFor($user);
        if ($scope !== null) {
            $equipment->where('ext_location_id', (int) $this->standortId);
        }
        $physicalLocationIds = $equipment->whereNotNull('location_id')->distinct()->pluck('location_id');
        $location = Location::query()->whereIn('id', $physicalLocationIds)->findOrFail((int) $this->locationId);
        try {
            $stocktake = $service->finish(
                $user,
                $location,
                $this->found,
                $this->unknown,
                $this->moveHere,
                $this->notes,
                $scope === null ? null : (int) $this->standortId,
            );
        } catch (ValidationException $e) {
            $this->addError('stocktake', collect($e->errors())->flatten()->first());

            return;
        }
        $this->reset('found', 'unknown', 'last', 'notes');
        $this->savedId = $stocktake->id;
    }

    public function render(StocktakeService $service)
    {
        /** @var User $user */
        $user = auth()->user();
        $scope = EquipmentService::equipmentManagementScope($user);
        $equipment = EquipmentService::managedEquipmentFor($user);
        if ($scope !== null && $this->standortId !== '') {
            $equipment->where('ext_location_id', (int) $this->standortId);
        }
        $physicalLocationIds = $equipment->whereNotNull('location_id')->distinct()->pluck('location_id');
        $locations = Location::query()->whereIn('id', $physicalLocationIds)->orderBy('kind')->orderBy('sort_order')->orderBy('name')->get();
        $location = $this->locationId !== '' ? $locations->firstWhere('id', (int) $this->locationId) : null;
        $standorte = $scope === null ? collect() : Standort::query()->whereIn('id', $scope)->orderBy('name')->get();
        $standortId = $scope === null ? null : (int) $this->standortId;
        $expected = $location && ($scope === null || in_array($standortId, $scope, true))
            ? $service->expected($location, $user, $standortId)
            : collect();
        $foreign = EquipmentService::managedEquipmentFor($user)->with(['location', 'compartment.location'])
            ->whereIn('id', $this->found)
            ->when($standortId !== null, fn ($query) => $query->where('ext_location_id', $standortId))
            ->get()
            ->reject(fn (Equipment $e) => $location && $e->location_id === $location->id);
        $history = Stocktake::with('user')->when($scope !== null, fn ($query) => $query->whereIn('ext_location_id', $scope))
            ->latest('finished_at')->limit(10)->get();
        $saved = $this->savedId
            ? Stocktake::query()->when($scope !== null, fn ($query) => $query->whereIn('ext_location_id', $scope))->find($this->savedId)
            : null;

        return view('ausruestung::livewire.stocktake-form', [
            'locations' => $locations,
            'standorte' => $standorte,
            'selectedStandort' => $standorte->firstWhere('id', $standortId),
            'location' => $location,
            'expected' => $expected,
            'foundCount' => $expected->whereIn('id', $this->found)->count(),
            'foreign' => $foreign,
            'saved' => $saved,
            'history' => $history,
        ]);
    }
}

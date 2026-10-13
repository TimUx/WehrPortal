<?php

namespace Modules\Ausruestung\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Ausruestung\src\Models\Location;
use Modules\Ausruestung\src\Models\Stocktake;
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

    public string $code = '';

    /** @var list<int> */
    public array $found = [];

    /** @var list<string> */
    public array $unknown = [];

    public bool $moveHere = true;

    public string $notes = '';

    public ?string $last = null;

    public ?int $savedId = null;

    public function updatedLocationId(): void
    {
        $this->reset('found', 'unknown', 'last', 'savedId', 'notes');
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
        $this->last = in_array($item->id, $this->found, true) ? $item->name.' war schon erfasst.' : '✓ '.$item->name.' ('.$item->code.')';
        $this->found = array_values(array_unique([...$this->found, $item->id]));
    }

    public function toggle(int $id): void
    {
        $this->found = in_array($id, $this->found, true) ? array_values(array_diff($this->found, [$id])) : [...$this->found, $id];
    }

    public function finish(StocktakeService $service): void
    {
        $location = Location::findOrFail((int) $this->locationId);
        try {
            $stocktake = $service->finish(auth()->user(), $location, $this->found, $this->unknown, $this->moveHere, $this->notes);
        } catch (ValidationException $e) {
            $this->addError('stocktake', collect($e->errors())->flatten()->first());

            return;
        }
        $this->reset('found', 'unknown', 'last', 'notes');
        $this->savedId = $stocktake->id;
    }

    public function render(StocktakeService $service)
    {
        $location = $this->locationId !== '' ? Location::find((int) $this->locationId) : null;
        $expected = $location ? $service->expected($location) : collect();
        $foreign = Equipment::with(['location', 'compartment.location'])->whereIn('id', $this->found)->get()
            ->reject(fn (Equipment $e) => $location && $e->location_id === $location->id);

        return view('ausruestung::livewire.stocktake-form', [
            'locations' => Location::orderBy('kind')->orderBy('sort_order')->orderBy('name')->get(),
            'location' => $location,
            'expected' => $expected,
            'foundCount' => $expected->whereIn('id', $this->found)->count(),
            'foreign' => $foreign,
            'saved' => $this->savedId ? Stocktake::find($this->savedId) : null,
            'history' => Stocktake::with('user')->latest('finished_at')->limit(10)->get(),
        ]);
    }
}

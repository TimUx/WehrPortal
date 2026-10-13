<?php

namespace App\Livewire\Catalog;

use App\Models\DressCode;
use App\Models\Place;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Orte and Kleidung, shared by the modules: each module shows this in its own menu with its own
 * permission (the gate in $ability), and all of them edit the same lists.
 */
class PlacesAndDress extends Component
{
    #[Locked]
    public string $ability = '';

    /** Optional argument of the gate, e.g. a model class for a policy */
    #[Locked]
    public ?string $argument = null;

    public array $place = ['name' => '', 'address' => ''];

    public ?int $editingPlace = null;

    public string $dress = '';

    public ?int $editingDress = null;

    public ?string $flash = null;

    public function mount(string $ability, ?string $argument = null): void
    {
        $this->ability = $ability;
        $this->argument = $argument;
        $this->authorizeEdit();
    }

    private function authorizeEdit(): void
    {
        abort_unless(Gate::allows($this->ability, $this->argument ? [$this->argument] : []), 403);
    }

    public function savePlace(): void
    {
        $this->authorizeEdit();
        $this->resetErrorBag();
        $name = trim($this->place['name']);
        $address = trim($this->place['address']);
        if ($name === '' || mb_strlen($name) > 150) {
            $this->addError('place', 'Bitte einen Namen angeben (höchstens 150 Zeichen).');

            return;
        }
        if (Place::whereRaw('lower(name) = ?', [mb_strtolower($name)])->when($this->editingPlace, fn ($q) => $q->whereKeyNot($this->editingPlace))->exists()) {
            $this->addError('place', 'Diesen Ort gibt es schon.');

            return;
        }
        $place = $this->editingPlace ? Place::findOrFail($this->editingPlace) : new Place(['sort_order' => (int) Place::max('sort_order') + 1]);
        $place->fill(['name' => $name, 'address' => $address !== '' ? mb_substr($address, 0, 255) : null])->save();
        $this->place = ['name' => '', 'address' => ''];
        $this->editingPlace = null;
        $this->flash = 'Ort gespeichert.';
    }

    public function editPlace(int $id): void
    {
        $place = Place::findOrFail($id);
        $this->editingPlace = $place->id;
        $this->place = ['name' => $place->name, 'address' => (string) $place->address];
    }

    public function deletePlace(int $id): void
    {
        $this->authorizeEdit();
        Place::whereKey($id)->delete();   // Termine keep the name as their Ort
        $this->flash = 'Ort gelöscht.';
    }

    public function saveDress(): void
    {
        $this->authorizeEdit();
        $this->resetErrorBag();
        $name = trim($this->dress);
        if ($name === '' || mb_strlen($name) > 100) {
            $this->addError('dress', 'Bitte eine Bezeichnung angeben (höchstens 100 Zeichen).');

            return;
        }
        if (DressCode::whereRaw('lower(name) = ?', [mb_strtolower($name)])->when($this->editingDress, fn ($q) => $q->whereKeyNot($this->editingDress))->exists()) {
            $this->addError('dress', 'Diese Kleidung gibt es schon.');

            return;
        }
        $dress = $this->editingDress ? DressCode::findOrFail($this->editingDress) : new DressCode(['sort_order' => (int) DressCode::max('sort_order') + 1]);
        $dress->fill(['name' => $name])->save();
        $this->dress = '';
        $this->editingDress = null;
        $this->flash = 'Kleidung gespeichert.';
    }

    public function editDress(int $id): void
    {
        $dress = DressCode::findOrFail($id);
        $this->editingDress = $dress->id;
        $this->dress = $dress->name;
    }

    public function deleteDress(int $id): void
    {
        $this->authorizeEdit();
        DressCode::whereKey($id)->delete();
        $this->flash = 'Kleidung gelöscht.';
    }

    /** One up or down; the order is the same in every module */
    public function move(string $list, int $id, int $direction): void
    {
        $this->authorizeEdit();
        $class = $list === 'place' ? Place::class : DressCode::class;
        $ids = $class::orderBy('sort_order')->orderBy('name')->pluck('id')->values()->all();
        $index = array_search($id, $ids, true);
        $target = $index === false ? null : $index + ($direction < 0 ? -1 : 1);
        if ($target === null || ! isset($ids[$target])) {
            return;
        }
        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
        foreach ($ids as $position => $rowId) {
            $class::whereKey($rowId)->update(['sort_order' => $position + 1]);
        }
    }

    public function render()
    {
        return view('livewire.catalog.places-and-dress', [
            'places' => Place::orderBy('sort_order')->orderBy('name')->get(),
            'dressCodes' => DressCode::orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }
}

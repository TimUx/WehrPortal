<?php

namespace Modules\Schluessel\src\Livewire;

use App\Models\Place;
use App\Services\ModuleRegistry;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Objektplaene\src\Models\PlanObject;
use Modules\Schluessel\src\Models\Key;
use Modules\Schluessel\src\Services\KeyService;

/** Create or change a key; what it opens comes from the shared places, the vehicles and the Objektpläne */
class KeyForm extends Component
{
    public ?Key $item = null;

    public array $form = [];

    public function mount(?Key $item = null): void
    {
        abort_unless(KeyService::canManage(auth()->user()), 403);
        $this->item = $item?->exists ? $item : null;
        $k = $this->item;
        $this->form = ['code' => (string) $k?->code, 'name' => (string) $k?->name, 'kind' => $k?->kind ?? 'schluessel', 'locking' => (string) $k?->locking,
            'place_id' => (string) ($k?->place_id ?? ''), 'vehicle_id' => (string) ($k?->vehicle_id ?? ''), 'object_id' => (string) ($k?->object_id ?? ''),
            'storage' => (string) $k?->storage, 'notes' => (string) $k?->notes];
    }

    public function save(KeyService $keys)
    {
        $this->resetErrorBag();
        try {
            $key = $keys->saveKey(auth()->user(), $this->item, $this->form);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return null;
        }

        return redirect()->route('schluessel.show', $key);
    }

    public function render()
    {
        $objects = app(ModuleRegistry::class)->isActive('objektplaene');

        return view('schluessel::livewire.key-form', [
            'places' => Place::options(),
            'vehicles' => Vehicle::where('is_active', true)->orderBy('sort_order')->orderBy('name')->pluck('name', 'id'),
            'objects' => $objects ? PlanObject::active()->orderBy('name')->pluck('name', 'id') : collect(),
            'nextCode' => $this->item ? null : app(KeyService::class)->nextCode(),
        ]);
    }
}

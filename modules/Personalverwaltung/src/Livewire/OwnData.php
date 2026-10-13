<?php

namespace Modules\Personalverwaltung\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Personalverwaltung\src\Services\PersonnelService;

/** Personalakte → Persönliche Daten: the Kamerad keeps his own; the Personalverwalter hear about changes */
class OwnData extends Component
{
    public array $data = [];

    public ?string $flash = null;

    public function mount(): void
    {
        abort_unless(PersonnelService::selfService(), 404);
        $me = auth()->user();
        $this->data = ['street' => (string) $me->street, 'postal_code' => (string) $me->postal_code, 'city' => (string) $me->city,
            'birth_date' => (string) $me->birth_date?->toDateString(), 'phone' => (string) $me->phone];
    }

    public function save(PersonnelService $personnel): void
    {
        $this->resetErrorBag();
        $this->flash = null;
        try {
            $changed = $personnel->savePersonalData(auth()->user(), auth()->user(), $this->data);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }
        $this->flash = $changed ? 'Gespeichert – die Personalverwaltung ist informiert.' : 'Keine Änderung.';
    }

    public function render()
    {
        return view('personalverwaltung::livewire.own-data', ['fields' => PersonnelService::activeFields()]);
    }
}

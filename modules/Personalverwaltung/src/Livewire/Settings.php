<?php

namespace Modules\Personalverwaltung\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Personalverwaltung\src\Services\PersonnelService;

/** Admin area of the Personalverwalter: which personal data is kept, and whether Kameraden keep their own */
class Settings extends Component
{
    public array $fields = [];

    public ?string $flash = null;

    public function mount(): void
    {
        $this->fields = PersonnelService::settings();
    }

    public function save(PersonnelService $personnel): void
    {
        $this->resetErrorBag();
        try {
            $personnel->saveSettings(auth()->user(), $this->fields);
        } catch (ValidationException $e) {
            $this->addError('settings', collect($e->errors())->flatten()->first());

            return;
        }
        $this->flash = 'Gespeichert.';
    }

    public function render()
    {
        return view('personalverwaltung::livewire.settings');
    }
}

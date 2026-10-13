<?php

namespace Modules\Personalverwaltung\src\Livewire;

use App\Models\Group;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Personalverwaltung\src\Services\PersonnelService;

/** Admin area of the Personalverwalter: which personal data is kept, and whether Kameraden keep their own */
class Settings extends Component
{
    public array $fields = [];

    public ?string $flash = null;

    /** How Jugendfeuerwehr time counts towards the Dienstzeit */
    public string $youthGroupId = '';

    public string $youthRule = 'full';

    public int $youthAge = 16;

    public function mount(): void
    {
        $this->fields = PersonnelService::settings();
        $rules = PersonnelService::serviceRules();
        [$this->youthGroupId, $this->youthRule, $this->youthAge] = [(string) ($rules['youth_group_id'] ?? ''), $rules['youth_rule'], $rules['youth_age']];
    }

    public function saveRules(PersonnelService $personnel): void
    {
        $this->resetErrorBag();
        try {
            $personnel->saveServiceRules(auth()->user(), $this->youthGroupId !== '' ? (int) $this->youthGroupId : null, $this->youthRule, $this->youthAge);
        } catch (ValidationException $e) {
            $this->addError('rules', collect($e->errors())->flatten()->first());

            return;
        }
        $this->flash = 'Regeln für die Dienstzeit gespeichert.';
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
        return view('personalverwaltung::livewire.settings', ['groups' => Group::orderBy('sort_order')->orderBy('name')->get(['id', 'name'])]);
    }
}

<?php

namespace Modules\Personalverwaltung\src\Livewire;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Personalverwaltung\src\Models\Honor;
use Modules\Personalverwaltung\src\Models\HonorType;
use Modules\Personalverwaltung\src\Services\PersonnelService;

/** Ehrungen: which are due this year (for the Jahreshauptversammlung), the kinds, the ones awarded */
class Honors extends Component
{
    #[Url(as: 'jahr')]
    public int $year = 0;

    public array $type = ['name' => '', 'after_years' => ''];

    public ?int $editingType = null;

    public string $awardDate = '';

    public ?string $flash = null;

    public function mount(): void
    {
        $this->year = $this->year ?: (int) now('Europe/Berlin')->year;
        $this->awardDate = PersonnelService::today()->toDateString();
    }

    public function saveType(PersonnelService $personnel): void
    {
        $years = trim((string) $this->type['after_years']) === '' ? null : (int) $this->type['after_years'];
        if ($this->run(fn () => $personnel->saveHonorType(auth()->user(), $this->editingType ? HonorType::findOrFail($this->editingType) : null, $this->type['name'], $years), 'Ehrung gespeichert.')) {
            $this->type = ['name' => '', 'after_years' => ''];
            $this->editingType = null;
        }
    }

    public function editType(int $id): void
    {
        $type = HonorType::findOrFail($id);
        $this->editingType = $type->id;
        $this->type = ['name' => $type->name, 'after_years' => (string) ($type->after_years ?? '')];
    }

    public function deleteType(int $id, PersonnelService $personnel): void
    {
        $this->run(fn () => $personnel->deleteHonorType(auth()->user(), HonorType::findOrFail($id)), 'Ehrung gelöscht. Bereits verliehene behalten ihren Namen.');
    }

    /** "Verliehen": the due Ehrung is entered with the chosen date */
    public function award(int $userId, int $typeId, PersonnelService $personnel): void
    {
        $member = User::findOrFail($userId);
        $this->run(fn () => $personnel->addHonor(auth()->user(), $member, ['honor_type_id' => $typeId, 'awarded_on' => $this->awardDate]),
            HonorType::find($typeId)?->name.' für '.$member->name.' eingetragen.');
    }

    private function run(callable $action, string $done): bool
    {
        $this->resetErrorBag();
        $this->flash = null;
        try {
            $action();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return false;
        }
        $this->flash = $done;

        return true;
    }

    public function render(PersonnelService $personnel)
    {
        $manager = auth()->user();

        return view('personalverwaltung::livewire.honors', [
            'due' => $personnel->dueHonors($manager, $this->year),
            'types' => HonorType::orderBy('sort_order')->orderBy('name')->get(),
            'awarded' => Honor::with('user')->whereIn('user_id', PersonnelService::visibleMembers($manager)->select('id'))
                ->whereYear('awarded_on', $this->year)->orderByDesc('awarded_on')->get(),
        ]);
    }
}

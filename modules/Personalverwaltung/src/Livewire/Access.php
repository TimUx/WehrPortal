<?php

namespace Modules\Personalverwaltung\src\Livewire;

use App\Models\Group;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Personalverwaltung\src\Services\PersonnelService;

/** Admins: which Abteilungen each Personalverwalter may see (none ticked = all) */
class Access extends Component
{
    /** @var array<int, list<string>> */
    public array $scopes = [];

    public ?string $flash = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->is_admin, 403);
        foreach ($this->managers() as $manager) {
            $this->scopes[$manager->id] = array_map('strval', PersonnelService::scopeOf($manager));
        }
    }

    private function managers()
    {
        return User::active()->where('is_admin', false)
            ->whereHas('moduleAccess', fn ($q) => $q->where('module', PersonnelService::MODULE)->where('role', 'personalverwalter'))
            ->orderBy('name')->get();
    }

    public function save(int $userId, PersonnelService $personnel): void
    {
        $this->resetErrorBag();
        try {
            $personnel->setScope(auth()->user(), User::findOrFail($userId), $this->scopes[$userId] ?? []);
        } catch (ValidationException $e) {
            $this->addError('scope', collect($e->errors())->flatten()->first());

            return;
        }
        $this->flash = 'Zugriff gespeichert.';
    }

    public function render()
    {
        return view('personalverwaltung::livewire.access', [
            'managers' => $this->managers(),
            'groups' => Group::options(),
        ]);
    }
}

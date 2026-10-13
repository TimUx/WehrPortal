<?php

namespace Modules\Personalverwaltung\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Personalverwaltung\src\Models\ChangeRequest;
use Modules\Personalverwaltung\src\Services\PersonnelService;

/** Personalverwalter: the open Änderungswünsche of their Kameraden, answered with "Erledigt" or "Nicht übernommen" */
class ChangeRequests extends Component
{
    /** @var array<int, string> answers by request id */
    public array $answers = [];

    public ?string $flash = null;

    public function boot(): void
    {
        abort_unless(PersonnelService::canManage(auth()->user()), 403);
    }

    public function answer(int $id, string $status, PersonnelService $personnel): void
    {
        $this->resetErrorBag();
        $request = ChangeRequest::with('user')->findOrFail($id);
        try {
            $personnel->answerChange(auth()->user(), $request, $status, $this->answers[$id] ?? null);
        } catch (ValidationException $e) {
            $this->addError('request-'.$id, collect($e->errors())->flatten()->first());

            return;
        }
        unset($this->answers[$id]);
        $this->flash = $request->user->name.': '.($status === 'done' ? 'erledigt' : 'nicht übernommen').', die Antwort ist raus.';
    }

    public function render(PersonnelService $personnel)
    {
        return view('personalverwaltung::livewire.change-requests', [
            'open' => $personnel->openRequests(auth()->user()),
            'recent' => ChangeRequest::with(['user', 'handler'])->where('status', '!=', 'open')
                ->whereIn('user_id', PersonnelService::visibleMembers(auth()->user())->select('id'))->latest('handled_at')->limit(15)->get(),
        ]);
    }
}

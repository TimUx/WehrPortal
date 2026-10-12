<?php

namespace Modules\Schichtplan\src\Livewire;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Schichtplan\src\Models\Assignment;
use Modules\Schichtplan\src\Models\Plan;
use Modules\Schichtplan\src\Services\SignupService;

/**
 * Self sign-up: every station and block with its free places; take a place or give it back until
 * the deadline. Planer and Stationsverantwortung confirm requests here; a planer can also sign up
 * someone else.
 */
class SignupBoard extends Component
{
    public Plan $plan;

    /** Whose places: oneself, or (planer) someone else of the target group */
    public string $personId = '';

    public string $rejectReason = '';

    public ?string $flash = null;

    public function mount(): void
    {
        $this->personId = (string) auth()->id();
    }

    private function canChoosePerson(): bool
    {
        return auth()->user()->canManageSchichtplan();
    }

    private function person(): ?User
    {
        if (! $this->canChoosePerson()) {
            return auth()->user();
        }

        return $this->personId !== '' ? User::active()->find((int) $this->personId) : null;
    }

    public function signUp(int $slotId, int $stationId, SignupService $signups): void
    {
        $person = $this->person();
        if (! $person) {
            $this->addError('signup', 'Bitte zuerst den Namen wählen.');

            return;
        }
        $this->run(function () use ($signups, $slotId, $stationId, $person) {
            $a = $signups->signUp(auth()->user(), $this->plan, $this->plan->slots()->findOrFail($slotId), $this->plan->stations()->findOrFail($stationId), $person);
            $this->flash = $person->name.' ist eingetragen'.($a->isRequested() ? ' – angefragt, wird noch bestätigt.' : '.');
        });
    }

    public function withdraw(int $assignmentId, SignupService $signups): void
    {
        $assignment = $this->plan->assignments()->findOrFail($assignmentId);
        $person = $this->person();
        if (! auth()->user()->canManageSchichtplan() && $assignment->user_id !== $person?->id) {
            $this->addError('signup', 'Austragen kann man nur die eigenen Schichten.');

            return;
        }
        $this->run(function () use ($signups, $assignment) {
            $signups->withdraw(auth()->user(), $assignment);
            $this->flash = 'Ausgetragen.';
        });
    }

    public function approve(int $assignmentId, SignupService $signups): void
    {
        $this->run(function () use ($signups, $assignmentId) {
            $signups->approve(auth()->user(), $this->plan->assignments()->findOrFail($assignmentId));
            $this->flash = 'Bestätigt.';
        });
    }

    public function reject(int $assignmentId, SignupService $signups): void
    {
        $this->run(function () use ($signups, $assignmentId) {
            $signups->reject(auth()->user(), $this->plan->assignments()->findOrFail($assignmentId), $this->rejectReason);
            $this->flash = 'Abgelehnt.';
            $this->rejectReason = '';
        });
    }

    private function run(callable $action): void
    {
        $this->resetErrorBag();
        $this->flash = null;
        try {
            $action();
        } catch (ValidationException $e) {
            $this->addError('signup', collect($e->errors())->flatten()->first());
        }
        $this->plan->refresh();
    }

    public function render(SignupService $signups)
    {
        $user = auth()->user();
        $plan = $this->plan->load(['stations.lead', 'slots']);
        $assignments = $plan->assignments()->with('user')->get();
        $person = $this->person();

        return view('schichtplan::livewire.signup-board', [
            'stations' => $plan->stations,
            'blocks' => $plan->slots,
            'cells' => $assignments->groupBy(fn (Assignment $a) => $a->slot_id.'-'.$a->station_id),
            'person' => $person,
            'mine' => $person ? $assignments->where('user_id', $person->id)->sortBy(fn ($a) => $plan->slots->firstWhere('id', $a->slot_id)?->starts_at)->values() : collect(),
            'people' => $this->canChoosePerson() ? $signups->audience($plan) : collect(),
            'requests' => $assignments->filter(fn (Assignment $a) => $a->isRequested() && SignupService::canApprove($user, $a))->values(),
            'open' => $plan->signupOpen(),
            'canManage' => $user->canManageSchichtplan(),
            'canChoose' => $this->canChoosePerson(),
        ]);
    }
}

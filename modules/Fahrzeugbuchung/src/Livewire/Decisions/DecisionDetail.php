<?php

namespace Modules\Fahrzeugbuchung\src\Livewire\Decisions;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Fahrzeugbuchung\src\Models\BookingDecision;
use Modules\Fahrzeugbuchung\src\Services\DecisionService;

class DecisionDetail extends Component
{
    public BookingDecision $decision;

    public string $note = '';

    public function approve(DecisionService $service): void
    {
        $this->decide($service, true);
    }

    public function reject(DecisionService $service): void
    {
        $this->decide($service, false);
    }

    /** Flip an existing decision */
    public function change(DecisionService $service): void
    {
        $this->run(fn () => $service->change($this->decision, auth()->user(), $this->note));
    }

    /** Take the decision back; the request is open again */
    public function reopen(DecisionService $service): void
    {
        $this->run(fn () => $service->reopen($this->decision, auth()->user(), $this->note));
    }

    private function decide(DecisionService $service, bool $approve): void
    {
        $this->run(fn () => $service->decide($this->decision, auth()->user(), $approve, $this->note));
    }

    private function run(callable $action): void
    {
        $this->authorize('decide-bookings');
        $this->validate(['note' => ['nullable', 'string', 'max:2000']]);

        try {
            $action();
            $this->note = '';
        } catch (ValidationException $e) {
            $this->addError('decision', $e->errors()['decision'][0] ?? 'Aktion nicht möglich.');
        }

        $this->decision->refresh();
    }

    public function render()
    {
        $this->decision->loadMissing(['booking.vehicle', 'booking.user', 'booking.group', 'replacedBooking.vehicle', 'replacedBooking.user', 'decider']);

        return view('fahrzeugbuchung::livewire.decisions.decision-detail', [
            'request' => $this->decision->booking,
            'conflicts' => $this->decision->conflictingBookings(),
        ]);
    }
}

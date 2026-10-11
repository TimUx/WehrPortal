<?php

namespace Modules\Fahrzeugbuchung\src\Livewire\Decisions;

use Livewire\Component;
use Modules\Fahrzeugbuchung\src\Models\BookingDecision;

class DecisionIndex extends Component
{
    public function render()
    {
        $with = ['booking.vehicle', 'booking.user', 'decider'];

        return view('fahrzeugbuchung::livewire.decisions.decision-index', [
            'open' => BookingDecision::with($with)->pending()->oldest()->get(),
            'closed' => BookingDecision::with($with)
                ->where('status', '!=', BookingDecision::STATUS_PENDING)
                ->latest('decided_at')
                ->limit(50)
                ->get(),
        ]);
    }
}

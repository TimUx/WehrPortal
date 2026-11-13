<?php

namespace Modules\Fahrzeugbuchung\src\Livewire\Decisions;

use Livewire\Component;
use Modules\Fahrzeugbuchung\src\Models\BookingDecision;
use Modules\Fahrzeugbuchung\src\Services\DecisionService;

class DecisionIndex extends Component
{
    public function render()
    {
        $with = ['booking.vehicle', 'booking.user', 'decider'];

        return view('fahrzeugbuchung::livewire.decisions.decision-index', [
            'open' => DecisionService::scopeDecidable(BookingDecision::with($with), auth()->user())->pending()->oldest()->get(),
            'closed' => DecisionService::scopeDecidable(BookingDecision::with($with), auth()->user())
                ->where('status', '!=', BookingDecision::STATUS_PENDING)
                ->latest('decided_at')
                ->limit(50)
                ->get(),
        ]);
    }
}

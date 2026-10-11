<?php

namespace Modules\Einsatzbericht\src\Livewire;

use App\Models\User;
use Livewire\Component;
use Modules\Einsatzbericht\src\Services\ReportService;

/** The Einsätze of one Kamerad: in the own Personalakte, or for the Wehrführung of anyone */
class MemberMissions extends Component
{
    public User $member;

    public function mount(?User $member = null): void
    {
        $this->member = $member?->exists ? $member : auth()->user();
        abort_unless($this->member->is(auth()->user()) || ReportService::canRelease(auth()->user()), 403);
    }

    public function render(ReportService $reports)
    {
        return view('einsatzbericht::livewire.member-missions', [
            ...$reports->memberRecord($this->member),
            'own' => $this->member->is(auth()->user()),
            'canOpen' => ReportService::canLead(auth()->user()),
        ]);
    }
}

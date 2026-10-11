<?php

namespace Modules\Lehrgaenge\src\Livewire;

use App\Models\User;
use Livewire\Component;
use Modules\Lehrgaenge\src\Models\Record;

/** Ausbildungsleiter and Wehrführung: the absolvierte Lehrgänge of one Kamerad, the running ones and the Absagen */
class MemberProfile extends Component
{
    public User $member;

    public function boot(): void
    {
        abort_unless(auth()->user()?->canViewLehrgaenge(), 403);
    }

    public function render()
    {
        $records = Record::with(['course', 'sessions'])->where('user_id', $this->member->id)->get();

        return view('lehrgaenge::livewire.member-profile', [
            'completed' => $records->where('status', 'completed')->sortByDesc(fn (Record $r) => $r->completed_on?->timestamp ?? 0)->values(),
            'open' => $records->whereIn('status', Record::OPEN)->sortBy('created_at')->values(),
            'cancelled' => $records->whereIn('status', ['cancelled', 'rejected'])->sortByDesc('cancelled_at')->values(),
        ]);
    }
}

<?php

namespace Modules\Kalender\src\Livewire;

use Livewire\Component;
use Modules\Kalender\src\Models\Poll;
use Modules\Kalender\src\Models\PollVote;

/** The Terminabfragen: open ones (marked when still to answer), and for the Terminverwalter the closed ones */
class PollList extends Component
{
    public bool $showClosed = false;

    public function render()
    {
        $user = auth()->user();
        $polls = Poll::visibleTo($user)->withCount('options')
            ->when(! $this->showClosed, fn ($q) => $q->whereNull('closed_at'))
            ->latest()->get();
        $answered = PollVote::where('user_id', $user->id)->join('kal_poll_options', 'kal_poll_options.id', '=', 'kal_poll_votes.option_id')
            ->distinct()->pluck('kal_poll_options.poll_id')->flip();

        return view('kalender::livewire.poll-list', ['polls' => $polls, 'answered' => $answered, 'manager' => $user->canManageKalender()]);
    }
}

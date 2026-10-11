<?php

namespace Modules\Kalender\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Kalender\src\Models\Poll;
use Modules\Kalender\src\Models\PollVote;
use Modules\Kalender\src\Services\PollService;

/**
 * A Terminabfrage: everyone's answers per proposal, the own row to tick, the best date marked;
 * the Terminverwalter fixes a date (it becomes an event), closes or reopens the poll.
 */
class PollView extends Component
{
    public Poll $poll;

    /** @var array<int, string> option id => yes | maybe | no | '' */
    public array $answers = [];

    public bool $rsvp = true;

    public bool $notify = true;

    public ?string $flash = null;

    public function mount(): void
    {
        abort_unless(Poll::visibleTo(auth()->user())->whereKey($this->poll->id)->exists(), 403);
        $mine = PollVote::where('user_id', auth()->id())->whereIn('option_id', $this->poll->options()->pluck('id'))->pluck('answer', 'option_id');
        $this->answers = $this->poll->options()->pluck('id')->mapWithKeys(fn ($id) => [$id => (string) ($mine[$id] ?? '')])->all();
    }

    public function save(PollService $polls): void
    {
        $this->run(fn () => $polls->vote(auth()->user(), $this->poll, $this->answers), 'Danke! Deine Antworten sind gespeichert.');
    }

    public function decide(int $optionId, PollService $polls)
    {
        $event = null;
        $this->run(function () use ($polls, $optionId, &$event) {
            $event = $polls->decide(auth()->user(), $this->poll, $this->poll->options()->findOrFail($optionId), $this->rsvp, $this->notify);
        }, 'Termin festgelegt.');

        return $event ? redirect()->route('kalender.show', $event) : null;
    }

    public function close(PollService $polls): void
    {
        $this->run(fn () => $polls->close(auth()->user(), $this->poll), 'Abfrage geschlossen.');
    }

    public function reopen(PollService $polls): void
    {
        $this->run(fn () => $polls->reopen(auth()->user(), $this->poll), 'Abfrage wieder geöffnet.');
    }

    public function delete(PollService $polls)
    {
        $polls->delete(auth()->user(), $this->poll);

        return redirect()->route('kalender.polls');
    }

    private function run(callable $action, string $done): void
    {
        $this->resetErrorBag();
        try {
            $action();
        } catch (ValidationException $e) {
            $this->addError('poll', collect($e->errors())->flatten()->first());

            return;
        }
        $this->flash = $done;
        $this->poll->refresh();
    }

    public function render(PollService $polls)
    {
        $user = auth()->user();

        return view('kalender::livewire.poll-view', [
            'results' => $polls->results($this->poll),
            'canVote' => $this->poll->isOpen() && $polls->audience($this->poll)->contains('id', $user->id),
            'manager' => $user->canManageKalender(),
        ]);
    }
}

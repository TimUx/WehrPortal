<?php

namespace App\Livewire\Feedback;

use App\Models\FeedbackThread;
use App\Services\FeedbackService;
use Livewire\Component;

/** The member's view of one conversation with the admins */
class FeedbackConversation extends Component
{
    public FeedbackThread $thread;

    public string $body = '';

    public function mount(): void
    {
        abort_unless($this->thread->user_id === auth()->id(), 403);

        if ($this->thread->unread_for_user) {
            $this->thread->update(['unread_for_user' => false]);
        }
    }

    public function reply(FeedbackService $service): void
    {
        abort_unless($this->thread->user_id === auth()->id(), 403);
        $this->validate(['body' => ['required', 'string', 'max:5000']], ['body.required' => 'Bitte schreib eine Nachricht.']);

        $service->reply($this->thread, auth()->user(), $this->body, fromAdmin: false);
        $this->body = '';
        $this->thread->refresh();
    }

    public function render()
    {
        return view('livewire.feedback.feedback-conversation', [
            'messages' => $this->thread->messages()->with('author')->get(),
        ]);
    }
}

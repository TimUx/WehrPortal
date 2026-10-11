<?php

namespace App\Livewire\Feedback;

use App\Services\FeedbackService;
use App\Services\ModuleRegistry;
use Livewire\Component;

/** Profile section: send feedback to the admins and see your conversations */
class FeedbackList extends Component
{
    public string $subject = '';

    public string $body = '';

    public ?string $moduleKey = null;

    public bool $showForm = false;

    public function send(FeedbackService $service): void
    {
        $this->validate([
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
        ], [
            'subject.required' => 'Bitte gib einen Betreff an.',
            'body.required' => 'Bitte schreib deine Nachricht.',
        ]);

        $thread = $service->start(auth()->user(), $this->subject, $this->body, $this->moduleKey ?: null);

        session()->flash('success', 'Danke! Dein Feedback ist bei den Admins angekommen.');
        $this->redirectRoute('feedback.show', $thread);
    }

    public function render()
    {
        return view('livewire.feedback.feedback-list', [
            'threads' => auth()->user()->feedbackThreads()->latest('last_message_at')->get(),
            'modules' => app(ModuleRegistry::class)->all(),
        ]);
    }
}

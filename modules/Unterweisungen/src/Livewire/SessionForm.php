<?php

namespace Modules\Unterweisungen\src\Livewire;

use App\Models\User;
use App\Services\ModuleRegistry;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Kalender\src\Models\Event;
use Modules\Unterweisungen\src\Models\Topic;
use Modules\Unterweisungen\src\Services\InstructionService;

/** Unterweisung durchführen: topic, day, instructor, participants (also from a Kalender event), documents */
class SessionForm extends Component
{
    use WithFileUploads;

    public array $form = ['topic_id' => '', 'held_on' => '', 'instructor_name' => '', 'notes' => ''];

    /** @var list<string> */
    public array $userIds = [];

    public bool $onlyRequired = true;

    public string $eventId = '';

    public array $uploads = [];

    public function boot(): void
    {
        abort_unless(InstructionService::canManage(auth()->user()), 403);
    }

    public function mount(): void
    {
        $this->form['held_on'] = InstructionService::today()->toDateString();
        $this->form['instructor_name'] = auth()->user()->name;
        $this->form['topic_id'] = (string) request()->integer('art') ?: '';
    }

    /** Takes over who was present at the Kalender event */
    public function updatedEventId(InstructionService $service): void
    {
        if ($this->eventId !== '') {
            $this->userIds = array_values(array_unique([...$this->userIds, ...array_map('strval', $service->fromEvent(auth()->user(), (int) $this->eventId))]));
            $this->onlyRequired = false;
        }
    }

    public function save(InstructionService $service)
    {
        $this->resetErrorBag();
        try {
            $session = $service->recordSession(auth()->user(), $this->form, array_map('intval', $this->userIds), $this->uploads);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return null;
        }

        return $this->redirectRoute('unterweisungen.session', $session);
    }

    public function render()
    {
        $topic = $this->form['topic_id'] !== '' ? InstructionService::topicsForManager(auth()->user())->find((int) $this->form['topic_id']) : null;
        $users = User::active()->with(['groups', 'functions'])->orderBy('name')->get();

        return view('unterweisungen::livewire.session-form', [
            'topics' => InstructionService::topicsForManager(auth()->user())->where('is_active', true)->orderBy('name')->get(),
            'users' => $topic && $this->onlyRequired ? $users->filter(fn (User $u) => $topic->requiredFor($u) || in_array((string) $u->id, $this->userIds, true)) : $users,
            'events' => ! InstructionService::isAtemschutzOnlyManager(auth()->user()) && app(ModuleRegistry::class)->isActive('kalender')
                ? Event::whereNull('source_id')->where('starts_at', '<=', now())->where('starts_at', '>=', now()->subDays(60))->orderByDesc('starts_at')->limit(30)->get() : collect(),
        ]);
    }
}

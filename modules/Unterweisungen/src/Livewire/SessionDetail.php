<?php

namespace Modules\Unterweisungen\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Unterweisungen\src\Models\Attendance;
use Modules\Unterweisungen\src\Models\Session;
use Modules\Unterweisungen\src\Services\InstructionService;

/** An Unterweisung that was held: the participants sign one after another (tablet passed around) */
class SessionDetail extends Component
{
    public Session $session;

    public ?int $signing = null;

    public string $signature = '';

    public function mount(Session $session): void
    {
        abort_unless(InstructionService::canManageSession(auth()->user(), $session), 403);
        $this->session = $session;
    }

    public function boot(): void
    {
        abort_unless(InstructionService::canManage(auth()->user()), 403);
    }

    public function sign(InstructionService $service): void
    {
        $attendance = Attendance::where('session_id', $this->session->id)->findOrFail((int) $this->signing);
        try {
            $service->sign(auth()->user(), $attendance, $this->signature);
        } catch (ValidationException $e) {
            $this->addError('signature', collect($e->errors())->flatten()->first());

            return;
        }
        // Straight on to the next one
        $next = Attendance::where('session_id', $this->session->id)->whereNull('signed_at')->join('users', 'users.id', '=', 'uw_attendances.user_id')
            ->orderBy('users.name')->value('uw_attendances.id');
        $this->signing = $next;
        $this->signature = '';
        $this->dispatch('signature-reset');
    }

    public function render()
    {
        return view('unterweisungen::livewire.session-detail', [
            'attendances' => $this->session->attendances()->with('user')->get()->sortBy(fn ($a) => $a->user?->name)->values(),
            'files' => $this->session->files,
        ]);
    }
}

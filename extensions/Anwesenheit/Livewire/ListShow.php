<?php

namespace Extensions\Anwesenheit\Livewire;

use Extensions\Anwesenheit\Models\AttendanceList;
use Extensions\Anwesenheit\Services\AttendanceService;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ListShow extends Component
{
    #[Locked]
    public int $listId;

    public ?string $flash = null;

    public function mount(AttendanceList $list): void
    {
        abort_unless(AttendanceService::canSee(auth()->user(), $list), 403);
        $this->listId = $list->id;
        $this->flash = session('flash');
    }

    public function resend(AttendanceService $service): void
    {
        $list = AttendanceList::findOrFail($this->listId);
        abort_unless($list->isSubmitted() && $this->mayResend($list), 403);

        $this->flash = $service->send(auth()->user(), $list)
            ? 'Die E-Mail mit PDF wurde versendet.'
            : (AttendanceService::recipientsFor($list) === [] ? 'Es ist kein E-Mail-Empfänger hinterlegt.' : 'Die E-Mail konnte nicht gesendet werden.');
    }

    private function mayResend(AttendanceList $list): bool
    {
        return AttendanceService::canResend(auth()->user(), $list);
    }

    public function delete(AttendanceService $service)
    {
        $service->delete(auth()->user(), AttendanceList::findOrFail($this->listId));
        session()->flash('flash', 'Liste gelöscht.');

        return $this->redirectRoute('anwesenheit.index', navigate: false);
    }

    public function render()
    {
        $list = AttendanceList::with(['leader', 'creator', 'participants', 'files'])->findOrFail($this->listId);
        $user = auth()->user();
        abort_unless(AttendanceService::canSee($user, $list), 403);

        return view('anwesenheit::livewire.list-show', [
            'list' => $list,
            'canEdit' => AttendanceService::canEdit($user, $list),
            'canDelete' => AttendanceService::canEdit($user, $list),
            'canResend' => $list->isSubmitted() && $this->mayResend($list),
            'recipients' => AttendanceService::recipientsFor($list),
        ]);
    }
}

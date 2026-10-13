<?php

namespace Modules\Lehrgaenge\src\Livewire;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Lehrgaenge\src\Models\Course;
use Modules\Lehrgaenge\src\Models\Deletion;
use Modules\Lehrgaenge\src\Models\Record;
use Modules\Lehrgaenge\src\Services\RegistrationService;

/**
 * Ausbildungsleiter and Wehrführung: the absolvierte Lehrgänge of one Kamerad, the running ones and the
 * Absagen. Admins and Ausbildungsleiter also enter Lehrgänge absolviert outside the app, correct and delete them.
 */
class MemberProfile extends Component
{
    public User $member;

    /** @var array{course: string, completed_on: string, note: string} */
    public array $entry = ['course' => '', 'completed_on' => '', 'note' => ''];

    public ?string $flash = null;

    public function boot(): void
    {
        abort_unless(auth()->user()?->canViewLehrgaenge(), 403);
    }

    public function addCompleted(RegistrationService $registrations): void
    {
        abort_unless(auth()->user()->canManageLehrgaenge(), 403);
        $this->resetErrorBag();

        try {
            $record = $registrations->recordCompleted($this->member, $this->entry['course'], $this->entry['completed_on'], $this->entry['note']);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->flash = '„'.$record->course->name.'“ als absolviert eingetragen.';
        $this->entry = ['course' => '', 'completed_on' => '', 'note' => ''];
    }

    /** The absolvierter Lehrgang being corrected, and the one being deleted with its optional reason */
    public ?int $editingId = null;

    /** @var array{course: string, completed_on: string, note: string} */
    public array $edit = ['course' => '', 'completed_on' => '', 'note' => ''];

    public ?int $deletingId = null;

    public string $deleteReason = '';

    public function startEdit(int $recordId): void
    {
        $record = $this->completedRecord($recordId);
        $this->resetErrorBag();
        $this->deletingId = null;
        $this->editingId = $record->id;
        $this->edit = ['course' => $record->course->name, 'completed_on' => (string) $record->completed_on?->toDateString(), 'note' => (string) $record->notes];
    }

    public function saveEdit(RegistrationService $registrations): void
    {
        $record = $this->completedRecord((int) $this->editingId);
        $this->resetErrorBag();
        try {
            $registrations->updateCompleted($record, $this->edit['course'], $this->edit['completed_on'], $this->edit['note']);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }
        $this->flash = '„'.$record->fresh('course')->course->name.'“ geändert.';
        $this->editingId = null;
    }

    public function startDelete(int $recordId): void
    {
        $this->completedRecord($recordId);
        $this->editingId = null;
        $this->deletingId = $recordId;
        $this->deleteReason = '';
    }

    public function cancel(): void
    {
        $this->editingId = $this->deletingId = null;
    }

    /** With a reason the deletion stays documented below; without one the Lehrgang is simply gone */
    public function confirmDelete(RegistrationService $registrations): void
    {
        $record = $this->completedRecord((int) $this->deletingId);
        $registrations->deleteCompleted($record, auth()->user(), $this->deleteReason);
        $this->flash = '„'.$record->course->name.'“ gelöscht'.(filled($this->deleteReason) ? ', die Begründung ist dokumentiert.' : '.');
        $this->deletingId = null;
        $this->deleteReason = '';
    }

    private function completedRecord(int $id): Record
    {
        abort_unless(auth()->user()->canManageLehrgaenge(), 403);

        return Record::with(['course', 'user'])->where('user_id', $this->member->id)->where('status', 'completed')->findOrFail($id);
    }

    public function render()
    {
        $records = Record::with(['course', 'sessions'])->where('user_id', $this->member->id)->get();

        return view('lehrgaenge::livewire.member-profile', [
            'completed' => $records->where('status', 'completed')->sortByDesc(fn (Record $r) => $r->completed_on?->timestamp ?? 0)->values(),
            'open' => $records->whereIn('status', Record::OPEN)->sortBy('created_at')->values(),
            'cancelled' => $records->whereIn('status', ['cancelled', 'rejected'])->sortByDesc('cancelled_at')->values(),
            'canManage' => auth()->user()->canManageLehrgaenge(),
            'deletions' => Deletion::with('deleter')->where('user_id', $this->member->id)->latest()->get(),
            'courseNames' => auth()->user()->canManageLehrgaenge() ? Course::orderBy('name')->pluck('name') : collect(),
        ]);
    }
}

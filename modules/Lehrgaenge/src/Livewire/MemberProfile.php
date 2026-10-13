<?php

namespace Modules\Lehrgaenge\src\Livewire;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Lehrgaenge\src\Models\Course;
use Modules\Lehrgaenge\src\Models\Record;
use Modules\Lehrgaenge\src\Services\RegistrationService;

/**
 * Ausbildungsleiter and Wehrführung: the absolvierte Lehrgänge of one Kamerad, the running ones and the
 * Absagen. The Ausbildungsleiter also enters Lehrgänge absolviert outside the app.
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

    public function deleteCompleted(int $recordId, RegistrationService $registrations): void
    {
        abort_unless(auth()->user()->canManageLehrgaenge(), 403);
        $record = Record::with('course')->where('user_id', $this->member->id)->findOrFail($recordId);

        try {
            $registrations->deleteEntered($record);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->flash = '„'.$record->course->name.'“ gelöscht.';
    }

    public function render()
    {
        $records = Record::with(['course', 'sessions'])->where('user_id', $this->member->id)->get();

        return view('lehrgaenge::livewire.member-profile', [
            'completed' => $records->where('status', 'completed')->sortByDesc(fn (Record $r) => $r->completed_on?->timestamp ?? 0)->values(),
            'open' => $records->whereIn('status', Record::OPEN)->sortBy('created_at')->values(),
            'cancelled' => $records->whereIn('status', ['cancelled', 'rejected'])->sortByDesc('cancelled_at')->values(),
            'canManage' => auth()->user()->canManageLehrgaenge(),
            'courseNames' => auth()->user()->canManageLehrgaenge() ? Course::orderBy('name')->pluck('name') : collect(),
        ]);
    }
}

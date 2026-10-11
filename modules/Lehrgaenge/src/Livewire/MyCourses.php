<?php

namespace Modules\Lehrgaenge\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Lehrgaenge\src\Models\Course;
use Modules\Lehrgaenge\src\Models\Record;
use Modules\Lehrgaenge\src\Services\RegistrationService;

/** The Kamerad's Lehrgangs-Dashboard: report Interesse, see the confirmed days, cancel, absolvierte Lehrgänge, Absagen */
class MyCourses extends Component
{
    public string $search = '';

    public ?int $requestCourseId = null;

    public string $requestNote = '';

    public ?int $cancelRecordId = null;

    public string $cancelReason = '';

    public ?string $flash = null;

    public function startRequest(int $courseId): void
    {
        $this->reset('cancelRecordId', 'cancelReason');
        $this->requestCourseId = $courseId;
        $this->requestNote = '';
    }

    public function sendRequest(RegistrationService $registrations): void
    {
        $this->validate(['requestNote' => ['nullable', 'string', 'max:1000']]);

        try {
            $record = $registrations->request(auth()->user(), Course::findOrFail($this->requestCourseId), $this->requestNote);
        } catch (ValidationException $e) {
            $this->addError('request', collect($e->errors())->flatten()->first());

            return;
        }

        $this->flash = 'Dein Interesse an „'.$record->course->name.'“ ist gemeldet. Die Ausbildungsleitung meldet dich in fox112 an.';
        $this->reset('requestCourseId', 'requestNote');
    }

    public function startCancel(int $recordId): void
    {
        $this->reset('requestCourseId', 'requestNote');
        $this->cancelRecordId = $recordId;
        $this->cancelReason = '';
    }

    public function cancel(RegistrationService $registrations): void
    {
        $this->validate(['cancelReason' => ['required', 'string', 'max:1000']], ['cancelReason.required' => 'Bitte kurz den Grund angeben.']);
        $record = Record::where('user_id', auth()->id())->findOrFail($this->cancelRecordId);

        try {
            $registrations->cancel($record, $this->cancelReason);
        } catch (ValidationException $e) {
            $this->addError('request', collect($e->errors())->flatten()->first());

            return;
        }

        $this->flash = '„'.$record->course->name.'“ abgesagt. Die Ausbildungsleitung ist informiert.';
        $this->reset('cancelRecordId', 'cancelReason');
    }

    public function render()
    {
        $records = Record::with(['course', 'sessions'])->where('user_id', auth()->id())->get();
        $term = trim($this->search);

        return view('lehrgaenge::livewire.my-courses', [
            'open' => $records->whereIn('status', Record::OPEN)->sortBy(fn (Record $r) => [$r->status === 'confirmed' ? 0 : 1, $r->starts_on?->timestamp ?? $r->created_at->timestamp])->values(),
            'courses' => Course::offered()->when($term !== '', fn ($q) => $q->where('name', 'ilike', '%'.$term.'%'))->orderBy('name')->get(),
            'openIds' => $records->whereIn('status', Record::OPEN)->pluck('course_id')->all(),
            'doneIds' => $records->where('status', 'completed')->pluck('course_id')->all(),
            'completed' => $records->where('status', 'completed')->sortByDesc(fn (Record $r) => $r->completed_on?->timestamp ?? 0)->values(),
            'cancelled' => $records->whereIn('status', ['cancelled', 'rejected'])->sortByDesc('cancelled_at')->values(),
        ]);
    }
}

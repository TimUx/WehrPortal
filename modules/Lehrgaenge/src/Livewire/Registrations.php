<?php

namespace Modules\Lehrgaenge\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Lehrgaenge\src\Models\Record;
use Modules\Lehrgaenge\src\Services\CatalogImporter;
use Modules\Lehrgaenge\src\Services\RegistrationService;
use RuntimeException;

/**
 * The Ausbildungsleiter's dashboard: Absagen to sign off in fox112, Bedarf per Lehrgang in the order
 * it came in, confirmed Lehrgänge with their days.
 */
class Registrations extends Component
{
    /** Record whose days are being entered */
    public ?int $sessionsRecordId = null;

    /** @var list<array{date: string, starts_at: string, ends_at: string, location: string}> */
    public array $sessions = [];

    /** Record being declined, with the comment for the Kamerad */
    public ?int $rejectRecordId = null;

    public string $rejectComment = '';

    public ?string $flash = null;

    public ?string $error = null;

    /** Also on every Livewire update, not only when the page is opened */
    public function boot(): void
    {
        abort_unless(auth()->user()?->canManageLehrgaenge(), 403);
    }

    public function editSessions(int $recordId): void
    {
        $record = Record::with('sessions')->whereIn('status', Record::OPEN)->findOrFail($recordId);
        $this->resetErrorBag();
        $this->sessionsRecordId = $record->id;
        $this->sessions = $record->sessions->map(fn ($s) => [
            'date' => $s->date->toDateString(), 'starts_at' => (string) $s->starts_at, 'ends_at' => (string) $s->ends_at, 'location' => (string) $s->location,
        ])->all() ?: [['date' => '', 'starts_at' => '08:00', 'ends_at' => '16:00', 'location' => '']];
    }

    /** Another day, with the times and place of the last one */
    public function addSession(): void
    {
        $last = end($this->sessions) ?: ['starts_at' => '08:00', 'ends_at' => '16:00', 'location' => ''];
        $this->sessions[] = ['date' => '', 'starts_at' => $last['starts_at'], 'ends_at' => $last['ends_at'], 'location' => $last['location']];
    }

    public function removeSession(int $index): void
    {
        unset($this->sessions[$index]);
        $this->sessions = array_values($this->sessions);
    }

    public function closeSessions(): void
    {
        $this->reset('sessionsRecordId', 'sessions', 'rejectRecordId', 'rejectComment');
        $this->resetErrorBag();
    }

    public function saveSessions(RegistrationService $registrations): void
    {
        $record = Record::with(['user', 'course'])->findOrFail($this->sessionsRecordId);

        try {
            $registrations->confirm($record, $this->sessions);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }

        $this->flash = $record->user->name.': Termine für „'.$record->course->name.'“ eingetragen, E-Mail ist raus.';
        $this->closeSessions();
    }

    public function complete(int $recordId, RegistrationService $registrations): void
    {
        $record = Record::with(['user', 'course'])->findOrFail($recordId);

        try {
            $registrations->complete($record);
        } catch (ValidationException $e) {
            $this->error = collect($e->errors())->flatten()->first();

            return;
        }

        $this->flash = $record->user->name.': „'.$record->course->name.'“ absolviert.';
    }

    public function startReject(int $recordId): void
    {
        $this->closeSessions();
        $this->rejectRecordId = $recordId;
        $this->rejectComment = '';
    }

    public function reject(RegistrationService $registrations): void
    {
        $this->validate(['rejectComment' => ['required', 'string', 'max:1000']], ['rejectComment.required' => 'Bitte einen Kommentar angeben – der Kamerad bekommt ihn per E-Mail.']);
        $record = Record::with(['user', 'course'])->findOrFail($this->rejectRecordId);

        try {
            $registrations->reject($record, $this->rejectComment);
        } catch (ValidationException $e) {
            $this->error = collect($e->errors())->flatten()->first();

            return;
        }

        $this->flash = $record->user->name.': „'.$record->course->name.'“ abgelehnt, E-Mail ist raus.';
        $this->reset('rejectRecordId', 'rejectComment');
    }

    public function import(CatalogImporter $importer): void
    {
        $this->reset('flash', 'error');

        try {
            $importer->import();
            $this->flash = 'Lehrgänge aktualisiert: '.CatalogImporter::lastRun()['message'];
        } catch (RuntimeException $e) {
            $this->error = 'Aktualisieren fehlgeschlagen: '.$e->getMessage();
        }
    }

    public function render()
    {
        $records = Record::with(['user', 'course', 'sessions'])
            ->where(fn ($q) => $q->whereIn('status', Record::OPEN)
                ->orWhere(fn ($c) => $c->whereIn('status', ['cancelled', 'rejected'])->where('cancelled_at', '>=', now()->subDays(90))))
            ->oldest()->get();
        $requested = $records->where('status', 'requested');

        return view('lehrgaenge::livewire.registrations', [
            'cancelled' => $records->where('status', 'cancelled')->sortByDesc('cancelled_at')->values(),
            'rejected' => $records->where('status', 'rejected')->sortByDesc('cancelled_at')->values(),
            // Bedarf per Lehrgang, the Kameraden in the order they came; the Lehrgang waiting longest on top
            'demand' => $requested->groupBy('course_id')->sortBy(fn ($group) => $group->min('created_at'))
                ->map(fn ($group) => ['course' => $group->first()->course, 'records' => $group->sortBy('created_at')->values()])->values(),
            'confirmed' => $records->where('status', 'confirmed')->sortBy('starts_on')->values(),
            'lastRun' => CatalogImporter::lastRun(),
        ]);
    }
}

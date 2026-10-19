<?php

namespace Modules\Lehrgaenge\src\Livewire;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Lehrgaenge\src\Models\Course;
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

    /** Kameraden anmelden: the Lehrgang, who, and a note */
    public string $registerCourse = '';

    /** @var list<string> */
    public array $registerMembers = [];

    public string $registerNote = '';

    public string $memberSearch = '';

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

    /** In fox112 angemeldet: the Kamerad moves from Interesse to the Warteliste (and back) */
    public function waitlist(int $recordId, bool $on = true): void
    {
        $record = Record::with(['user', 'course'])->findOrFail($recordId);
        $this->reset('flash', 'error');
        try {
            app(RegistrationService::class)->waitlist($record, $on);
        } catch (ValidationException $e) {
            $this->error = collect($e->errors())->flatten()->first();

            return;
        }
        $this->flash = $record->user->name.': „'.$record->course->name.'“ '.($on ? 'steht auf der Warteliste, E-Mail ist raus.' : 'wieder unter Interesse.');
    }

    /** Absage signed off in fox112: no longer red on the start page (and back) */
    public function markCancelSeen(int $recordId, bool $seen = true): void
    {
        $record = Record::with(['user', 'course'])->findOrFail($recordId);
        $this->reset('flash', 'error');
        try {
            app(RegistrationService::class)->markCancelSeen($record, $seen);
        } catch (ValidationException $e) {
            $this->error = collect($e->errors())->flatten()->first();
        }
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

        $this->flash = $record->user->getAttribute('name').': „'.$record->course->getAttribute('name').'“ abgelehnt, E-Mail ist raus.';
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

    public function registerMembers(RegistrationService $registrations): void
    {
        $this->reset('flash', 'error');
        $course = Course::find((int) $this->registerCourse);
        if (! $course) {
            $this->error = 'Bitte einen Lehrgang wählen.';

            return;
        }
        try {
            $result = $registrations->registerFor(auth()->user(), $course, array_map('intval', $this->registerMembers), $this->registerNote);
        } catch (ValidationException $e) {
            $this->error = collect($e->errors())->flatten()->first();

            return;
        }
        $this->flash = ($result['registered'] ? 'Angemeldet für „'.$course->name.'“: '.implode(', ', $result['registered']).'.' : '')
            .($result['skipped'] ? ' Schon angemeldet oder absolviert: '.implode(', ', $result['skipped']).'.' : '');
        $this->reset('registerMembers', 'registerNote', 'memberSearch');
    }

    public function render()
    {
        $records = Record::with(['user', 'course', 'sessions'])
            ->where(fn ($q) => $q->whereIn('status', Record::OPEN)
                ->orWhere(fn ($c) => $c->whereIn('status', ['cancelled', 'rejected'])->where('cancelled_at', '>=', now()->subDays(90))))
            ->oldest()->get();
        $requested = $records->where('status', 'requested')->whereNull('waitlisted_at');
        $group = fn ($records) => $records->groupBy('course_id')->sortBy(fn ($group) => $group->min('created_at'))
            ->map(fn ($group) => ['course' => $group->first()->course, 'records' => $group->sortBy('created_at')->values()])->values();

        return view('lehrgaenge::livewire.registrations', [
            'cancelled' => $records->where('status', 'cancelled')->sortByDesc('cancelled_at')->values(),
            'rejected' => $records->where('status', 'rejected')->sortByDesc('cancelled_at')->values(),
            // Bedarf per Lehrgang, the Kameraden in the order they came; the Lehrgang waiting longest on top
            'demand' => $group($requested),
            // Registered in fox112, waiting for a place
            'waitlist' => $group($records->where('status', 'requested')->whereNotNull('waitlisted_at')),
            'confirmed' => $records->where('status', 'confirmed')->sortBy('starts_on')->values(),
            'lastRun' => CatalogImporter::lastRun(),
            'offeredCourses' => Course::offered()->orderBy('name')->get(['id', 'name']),
            'memberOptions' => $this->registerCourse === '' ? collect() : User::active()->when(trim($this->memberSearch) !== '', fn ($q) => $q->where('name', 'ilike', '%'.trim($this->memberSearch).'%'))
                ->orderBy('name')->limit(200)->get(['id', 'name']),
        ]);
    }
}

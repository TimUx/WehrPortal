<?php

namespace Modules\Schichtplan\src\Livewire;

use App\Models\Group;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Schichtplan\src\Models\Assignment;
use Modules\Schichtplan\src\Models\Participant;
use Modules\Schichtplan\src\Models\Plan;
use Modules\Schichtplan\src\Models\Slot;
use Modules\Schichtplan\src\Models\Station;
use Modules\Schichtplan\src\Models\Template;
use Modules\Schichtplan\src\Services\CalendarLink;
use Modules\Schichtplan\src\Services\PlanMailer;
use Modules\Schichtplan\src\Services\PlanService;
use Modules\Schichtplan\src\Services\ShiftPlanner;

/** The planer's page: settings, stations, participants, distribution, publish and mail */
class PlanEditor extends Component
{
    use SignupSettings;

    public Plan $plan;

    // Settings
    public string $title = '';

    public string $location = '';

    public string $date = '';

    public string $startTime = '';

    public string $endTime = '';

    public int $shiftMinutes = 60;

    public int $maxConsecutive = 2;

    public ?int $groupId = null;

    // Stations
    public string $newStationName = '';

    public int $newStationCapacity = 1;

    /** @var array<int, array{name: string, capacity: int}> */
    public array $stationEdits = [];

    /** @var array<int, string> station id => user id of the Stationsverantwortung ('' = none) */
    public array $stationLeads = [];

    public ?int $templateId = null;

    public string $templateName = '';

    // Participants
    /** @var array<int, string> */
    public array $addUserIds = [];

    public ?int $addGroupId = null;

    /** @var array<int, array{from: string, until: string}> */
    public array $availability = [];

    public ?string $flash = null;

    public function mount(): void
    {
        $this->authorize('manage', $this->plan);
        $this->fillFromPlan();
    }

    private function fillFromPlan(): void
    {
        $this->plan->refresh();
        $this->title = $this->plan->title;
        $this->location = (string) $this->plan->location;
        $this->date = PlanTimes::local($this->plan->starts_at, 'Y-m-d');
        $this->startTime = PlanTimes::local($this->plan->starts_at, 'H:i');
        $this->endTime = PlanTimes::local($this->plan->ends_at, 'H:i');
        $this->shiftMinutes = $this->plan->shift_minutes;
        $this->maxConsecutive = $this->plan->max_consecutive;
        $this->groupId = $this->plan->group_id;
        $this->addGroupId ??= $this->plan->group_id;
        $this->fillSignup($this->plan);

        $this->stationEdits = $this->plan->stations()->get()
            ->mapWithKeys(fn (Station $s) => [$s->id => ['name' => $s->name, 'capacity' => $s->capacity]])->all();
        $this->stationLeads = $this->plan->stations()->get()
            ->mapWithKeys(fn (Station $s) => [$s->id => (string) ($s->lead_user_id ?? '')])->all();

        $this->availability = $this->plan->participants()->get()
            ->mapWithKeys(fn (Participant $p) => [$p->user_id => [
                'from' => PlanTimes::local($p->available_from, 'Y-m-d\TH:i'),
                'until' => PlanTimes::local($p->available_until, 'Y-m-d\TH:i'),
            ]])->all();
    }

    // ─── settings ────────────────────────────────────────────────────────────

    public function saveSettings(PlanService $plans): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:150'],
            'location' => ['nullable', 'string', 'max:150'],
            'date' => ['required', 'date_format:Y-m-d'],
            'startTime' => ['required', 'date_format:H:i'],
            'endTime' => ['required', 'date_format:H:i'],
            'shiftMinutes' => ['required', 'integer', 'min:15', 'max:720'],
            'maxConsecutive' => ['required', 'integer', 'min:1', 'max:24'],
            'groupId' => ['nullable', 'exists:groups,id'],
            ...$this->signupRules(),
        ]);

        [$start, $end] = PlanTimes::fromInputs($this->date, $this->startTime, $this->endTime);

        try {
            $rebuilt = $plans->update($this->plan, [
                'title' => trim($this->title),
                'location' => trim($this->location) ?: null,
                'starts_at' => $start,
                'ends_at' => $end,
                'shift_minutes' => $this->shiftMinutes,
                'max_consecutive' => $this->maxConsecutive,
                'group_id' => $this->groupId,
                ...$this->signupData(),
            ]);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError(in_array($field, ['signup', 'signupUntil', 'maxShifts'], true) ? $field : 'shiftMinutes', $messages[0]);
            }

            return;
        }

        $this->fillFromPlan();
        $this->flash = $rebuilt ? 'Gespeichert. Die Zeitblöcke wurden neu angelegt; bitte den Plan neu füllen.' : 'Gespeichert.';
    }

    // ─── stations ────────────────────────────────────────────────────────────

    public function addStation(PlanService $plans): void
    {
        $this->validate([
            'newStationName' => ['required', 'string', 'max:100'],
            'newStationCapacity' => ['required', 'integer', 'min:1', 'max:20'],
        ], ['newStationName.required' => 'Wie heißt die Station?']);

        $plans->addStation($this->plan, $this->newStationName, $this->newStationCapacity);
        $this->reset('newStationName', 'newStationCapacity');
        $this->fillFromPlan();
    }

    public function saveStation(int $stationId, PlanService $plans): void
    {
        $station = $this->plan->stations()->findOrFail($stationId);
        $this->validate([
            "stationEdits.$stationId.name" => ['required', 'string', 'max:100'],
            "stationEdits.$stationId.capacity" => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $plans->updateStation($station, $this->stationEdits[$stationId]['name'], (int) $this->stationEdits[$stationId]['capacity']);
        $this->fillFromPlan();
    }

    public function saveStationLead(int $stationId, PlanService $plans): void
    {
        $station = $this->plan->stations()->findOrFail($stationId);
        $userId = (int) ($this->stationLeads[$stationId] ?? 0) ?: null;

        if ($userId && ! User::active()->whereKey($userId)->first()?->canUseModule('schichtplan')) {
            $this->addError("stationLeads.$stationId", 'Diese Person hat keinen Zugang zum Schichtplan.');

            return;
        }

        $plans->setStationLead($station, $userId);
        $this->flash = $userId ? 'Stationsverantwortung für „'.$station->name.'“ gesetzt.' : 'Stationsverantwortung für „'.$station->name.'“ entfernt.';
    }

    public function removeStation(int $stationId, PlanService $plans): void
    {
        $plans->removeStation($this->plan->stations()->findOrFail($stationId));
        $this->fillFromPlan();
    }

    public function applyTemplate(PlanService $plans): void
    {
        if ($this->templateId) {
            $plans->applyTemplate($this->plan, Template::findOrFail($this->templateId));
            $this->templateId = null;
            $this->fillFromPlan();
        }
    }

    public function saveTemplate(PlanService $plans): void
    {
        $this->validate(['templateName' => ['required', 'string', 'max:100']], ['templateName.required' => 'Wie soll die Vorlage heißen?']);
        $plans->saveTemplate($this->plan, $this->templateName);
        $this->flash = 'Vorlage „'.trim($this->templateName).'“ gespeichert.';
        $this->reset('templateName');
    }

    // ─── participants ────────────────────────────────────────────────────────

    public function addParticipants(PlanService $plans): void
    {
        $added = $plans->addParticipants($this->plan, array_map('intval', $this->addUserIds));
        $this->reset('addUserIds');
        $this->fillFromPlan();
        $this->flash = $added.' Teilnehmer hinzugefügt.';
    }

    public function addGroup(PlanService $plans): void
    {
        if ($this->addGroupId) {
            $added = $plans->addGroup($this->plan, Group::findOrFail($this->addGroupId));
            $this->fillFromPlan();
            $this->flash = $added.' Teilnehmer aus der Abteilung hinzugefügt.';
        }
    }

    public function removeParticipant(int $userId, PlanService $plans): void
    {
        $plans->removeParticipant($this->plan, $userId);
        $this->fillFromPlan();
    }

    public function saveAvailability(int $userId, PlanService $plans): void
    {
        try {
            $plans->setAvailability(
                $this->plan, $userId,
                PlanTimes::parseLocal($this->availability[$userId]['from'] ?? null),
                PlanTimes::parseLocal($this->availability[$userId]['until'] ?? null),
            );
        } catch (ValidationException $e) {
            $this->addError("availability.$userId", collect($e->errors())->flatten()->first());

            return;
        }

        $this->fillFromPlan();
    }

    // ─── distribution ────────────────────────────────────────────────────────

    public function distribute(ShiftPlanner $planner): void
    {
        $planner->fill($this->plan);
        $this->flash = 'Plan gefüllt. Von Hand gesetzte Plätze (Schloss) sind geblieben.';
    }

    public function setSeat(int $slotId, int $stationId, ?int $currentUserId, $newUserId, PlanService $plans): void
    {
        try {
            $plans->setSeat(
                $this->plan,
                $this->plan->slots()->findOrFail($slotId),
                $this->plan->stations()->findOrFail($stationId),
                $currentUserId ?: null,
                filled($newUserId) ? (int) $newUserId : null,
            );
        } catch (ValidationException $e) {
            $this->flash = collect($e->errors())->flatten()->first();
        }
    }

    public function toggleLock(int $assignmentId, PlanService $plans): void
    {
        $plans->toggleLock($this->plan->assignments()->findOrFail($assignmentId));
    }

    public function clearAll(): void
    {
        $this->plan->assignments()->delete();
        $this->flash = 'Alle Einteilungen entfernt.';
    }

    // ─── publish and mail ────────────────────────────────────────────────────

    public function publish(PlanMailer $mailer): void
    {
        $sent = $mailer->publish($this->plan);
        $this->plan->refresh();
        $this->flash = $this->plan->isSelfSignup()
            ? 'Veröffentlicht – '.$sent.' Kameraden sind zum Eintragen eingeladen.'
            : 'Veröffentlicht und an '.$sent.' Teilnehmer geschickt.';
    }

    public function sendMail(PlanMailer $mailer): void
    {
        $sent = $mailer->send($this->plan);
        $this->plan->refresh();
        $this->flash = 'Plan an '.$sent.' Teilnehmer geschickt.';
    }

    public function delete()
    {
        app(CalendarLink::class)->detach($this->plan);
        $this->plan->delete();

        return redirect()->route('schichtplan.index');
    }

    public function render(PlanService $plans)
    {
        $plan = $this->plan->load(['stations.lead', 'slots', 'participants.user']);
        $assignments = $plan->assignments()->with('user')->get();

        $participantIds = $plan->participants->pluck('user_id');
        $candidates = User::active()->whereNotIn('id', $participantIds)->orderBy('name')->get()
            ->filter(fn (User $u) => $u->canUseModule('schichtplan'));

        return view('schichtplan::livewire.plan-editor', [
            'stations' => $plan->stations,
            'blocks' => $plan->slots,
            'participants' => $plan->participants->sortBy('user.name')->values(),
            'cells' => $assignments->groupBy(fn (Assignment $a) => $a->slot_id.'-'.$a->station_id),
            'availableFor' => $plan->slots->mapWithKeys(fn (Slot $slot) => [$slot->id => $plan->participants
                ->filter(fn (Participant $p) => $p->isAvailableFor($slot))->sortBy('user.name')
                ->mapWithKeys(fn (Participant $p) => [$p->user_id => $p->user->name])->all()]),
            'candidates' => $candidates,
            // Stationsverantwortung: participants first, then everyone else with access
            'leadOptions' => $plan->participants->pluck('user')->sortBy('name')->concat($candidates)->unique('id')->pluck('name', 'id'),
            'groups' => Group::orderBy('sort_order')->orderBy('name')->pluck('name', 'id'),
            'templates' => Template::orderBy('name')->pluck('name', 'id'),
            'stats' => $plans->stats($plan),
            'requests' => $assignments->filter->isRequested()->count(),
            ...$this->signupOptions(),
        ]);
    }
}

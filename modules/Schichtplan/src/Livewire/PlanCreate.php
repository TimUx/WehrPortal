<?php

namespace Modules\Schichtplan\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Kalender\src\Models\Event;
use Modules\Schichtplan\src\Models\Template;
use Modules\Schichtplan\src\Services\CalendarLink;
use Modules\Schichtplan\src\Services\PlanLocationAccess;
use Modules\Schichtplan\src\Services\PlanService;

class PlanCreate extends Component
{
    use SignupSettings;

    public string $title = '';

    public string $location = '';

    public string $date = '';

    public string $startTime = '14:00';

    public string $endTime = '22:00';

    public int $shiftMinutes = 60;

    public int $maxConsecutive = 2;

    public ?int $groupId = null;

    public ?int $locationId = null;

    public ?int $templateId = null;

    /** "Schichtplan anlegen" on a Kalender event: the plan takes its data and links to it */
    #[Url(as: 'termin')]
    public ?int $eventId = null;

    public function mount(PlanLocationAccess $locations): void
    {
        $this->date = now()->addWeek()->setTimezone('Europe/Berlin')->format('Y-m-d');
        $this->locationId = $locations->defaultLocationId(auth()->user());

        if ($event = $this->event()) {
            $eventLocations = $event->locationIds();
            if (count($eventLocations) === 1 && in_array($eventLocations[0], $locations->managedLocationIds(auth()->user()), true)) {
                $this->locationId = $eventLocations[0];
            }
            $start = $event->starts_at->copy()->setTimezone('Europe/Berlin');
            $this->title = $event->title;
            $this->location = (string) $event->location;
            $this->date = $start->format('Y-m-d');
            if (! $event->all_day) {
                $this->startTime = $start->format('H:i');
                $this->endTime = $event->ends_at->copy()->setTimezone('Europe/Berlin')->format('H:i');
            }
            $this->audienceGroups = $event->groups()->where('groups.ext_location_id', $this->locationId)
                ->pluck('groups.id')->map(fn ($id) => (string) $id)->all();
            $this->audienceFunctions = $event->functions()->pluck('member_functions.id')->map(fn ($id) => (string) $id)->all();
        } else {
            $this->eventId = null;
        }
    }

    public function updatedLocationId(PlanLocationAccess $locations): void
    {
        $allowed = $locations->groupOptions($this->locationId)->keys()->map(fn ($id) => (string) $id)->all();
        $this->groupId = in_array((string) $this->groupId, $allowed, true) ? $this->groupId : null;
        $this->audienceGroups = array_values(array_intersect($this->audienceGroups, $allowed));
    }

    /** The event, while the Kalender is on and the planer may see it */
    private function event(): ?Event
    {
        return $this->eventId && CalendarLink::available() ? Event::visibleTo(auth()->user())->find($this->eventId) : null;
    }

    public function save(PlanService $plans, PlanLocationAccess $locations)
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
            'templateId' => ['nullable', 'exists:sp_templates,id'],
            'locationId' => $locations->enabled()
                ? ['required', 'integer', 'in:'.implode(',', array_keys($locations->locationOptions(auth()->user())->all()))]
                : ['nullable'],
            ...$this->signupRules(),
        ], ['title.required' => 'Bitte gib an, wofür der Plan ist, z. B. „Dorffest – Pommesstand“.']);

        [$start, $end] = PlanTimes::fromInputs($this->date, $this->startTime, $this->endTime);

        try {
            $plan = $plans->create([
                'title' => trim($this->title),
                'location' => trim($this->location) ?: null,
                'starts_at' => $start,
                'ends_at' => $end,
                'shift_minutes' => $this->shiftMinutes,
                'max_consecutive' => $this->maxConsecutive,
                'group_id' => $this->groupId,
                'ext_location_id' => $this->locationId,
                'event_id' => $this->event()?->id,
                ...$this->signupData(),
            ], auth()->user());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $target = match (true) {
                    $field === 'locationId' => 'locationId',
                    $field === 'groupId' => 'groupId',
                    in_array($field, ['signup', 'signupUntil', 'maxShifts'], true) => $field,
                    default => 'shiftMinutes',
                };
                $this->addError($target, $messages[0]);
            }

            return null;
        }

        if ($this->templateId) {
            $plans->applyTemplate($plan, Template::findOrFail($this->templateId));
        }

        return redirect()->route('schichtplan.edit', $plan);
    }

    public function render(PlanLocationAccess $locations)
    {
        return view('schichtplan::livewire.plan-create', [
            'groups' => $locations->groupOptions($this->locationId),
            'locationOptions' => $locations->locationOptions(auth()->user()),
            'locationsEnabled' => $locations->enabled(),
            'templates' => Template::orderBy('name')->pluck('name', 'id'),
            'event' => $this->event(),
            ...$this->signupOptions($locations),
        ]);
    }
}

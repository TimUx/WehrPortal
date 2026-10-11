<?php

namespace Modules\Schichtplan\src\Livewire;

use App\Models\Group;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Kalender\src\Models\Event;
use Modules\Schichtplan\src\Models\Template;
use Modules\Schichtplan\src\Services\CalendarLink;
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

    public ?int $templateId = null;

    /** "Schichtplan anlegen" on a Kalender event: the plan takes its data and links to it */
    #[Url(as: 'termin')]
    public ?int $eventId = null;

    public function mount(): void
    {
        $this->date = now()->addWeek()->setTimezone('Europe/Berlin')->format('Y-m-d');

        if ($event = $this->event()) {
            $start = $event->starts_at->copy()->setTimezone('Europe/Berlin');
            $this->title = $event->title;
            $this->location = (string) $event->location;
            $this->date = $start->format('Y-m-d');
            if (! $event->all_day) {
                $this->startTime = $start->format('H:i');
                $this->endTime = $event->ends_at->copy()->setTimezone('Europe/Berlin')->format('H:i');
            }
            $this->audienceGroups = $event->groups()->pluck('groups.id')->map(fn ($id) => (string) $id)->all();
            $this->audienceFunctions = $event->functions()->pluck('member_functions.id')->map(fn ($id) => (string) $id)->all();
        } else {
            $this->eventId = null;
        }
    }

    /** The event, while the Kalender is on and the planer may see it */
    private function event(): ?Event
    {
        return $this->eventId && CalendarLink::available() ? Event::visibleTo(auth()->user())->find($this->eventId) : null;
    }

    public function save(PlanService $plans)
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
                'event_id' => $this->event()?->id,
                ...$this->signupData(),
            ], auth()->user());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError(in_array($field, ['signup', 'signupUntil', 'maxShifts'], true) ? $field : 'shiftMinutes', $messages[0]);
            }

            return null;
        }

        if ($this->templateId) {
            $plans->applyTemplate($plan, Template::findOrFail($this->templateId));
        }

        return redirect()->route('schichtplan.edit', $plan);
    }

    public function render()
    {
        return view('schichtplan::livewire.plan-create', [
            'groups' => Group::orderBy('sort_order')->orderBy('name')->pluck('name', 'id'),
            'templates' => Template::orderBy('name')->pluck('name', 'id'),
            'event' => $this->event(),
            ...$this->signupOptions(),
        ]);
    }
}

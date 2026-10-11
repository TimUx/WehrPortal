<?php

namespace Modules\Schichtplan\src\Livewire;

use App\Models\Group;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Schichtplan\src\Models\Template;
use Modules\Schichtplan\src\Services\PlanService;

class PlanCreate extends Component
{
    public string $title = '';

    public string $location = '';

    public string $date = '';

    public string $startTime = '14:00';

    public string $endTime = '22:00';

    public int $shiftMinutes = 60;

    public int $maxConsecutive = 2;

    public ?int $groupId = null;

    public ?int $templateId = null;

    public function mount(): void
    {
        $this->date = now()->addWeek()->setTimezone('Europe/Berlin')->format('Y-m-d');
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
            ], auth()->user());
        } catch (ValidationException $e) {
            $this->addError('shiftMinutes', collect($e->errors())->flatten()->first());

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
        ]);
    }
}

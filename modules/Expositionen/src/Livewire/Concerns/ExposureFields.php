<?php

namespace Modules\Expositionen\src\Livewire\Concerns;

use Modules\Einsatzbericht\src\Models\Report;
use Modules\Expositionen\src\Models\Exposure;
use Modules\Expositionen\src\Services\ExposureService;

/** The form state of an exposure, shared by the single form and the crew form */
trait ExposureFields
{
    public array $form = [];

    protected function blankForm(?Report $report = null): array
    {
        return [
            'occurred_on' => ($report?->alarmed_at?->copy()->setTimezone('Europe/Berlin') ?? now('Europe/Berlin'))->toDateString(),
            'occasion' => $report ? ExposureService::occasionFor($report) : '', 'activity' => '', 'activity_text' => '',
            'substances' => [], 'other_substances' => '', 'duration_minutes' => '', 'routes' => [], 'protection' => [], 'notes' => '',
        ];
    }

    protected function formOf(Exposure $e): array
    {
        $known = array_values(array_intersect($e->substances ?? [], Exposure::SUBSTANCES));

        return [
            'occurred_on' => $e->occurred_on->toDateString(), 'occasion' => $e->occasion, 'activity' => $e->activity['kind'] ?? '',
            'activity_text' => (string) ($e->activity['text'] ?? ''), 'substances' => $known,
            'other_substances' => implode(', ', array_diff($e->substances ?? [], $known)), 'duration_minutes' => (string) ($e->duration_minutes ?? ''),
            'routes' => $e->routes ?? [], 'protection' => $e->protection ?? [], 'notes' => (string) $e->notes,
        ];
    }
}

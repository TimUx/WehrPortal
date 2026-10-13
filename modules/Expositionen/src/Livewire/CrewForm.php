<?php

namespace Modules\Expositionen\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Expositionen\src\Livewire\Concerns\ExposureFields;
use Modules\Expositionen\src\Models\Exposure;
use Modules\Expositionen\src\Services\ExposureService;

/** From the Einsatzbericht: one exposure for several Kameraden of the crew, each confirms their own */
class CrewForm extends Component
{
    use ExposureFields;

    public Report $report;

    /** @var list<string> */
    public array $crewIds = [];

    public function mount(): void
    {
        abort_unless(ExposureService::canPropose(auth()->user()), 403);
        $this->form = $this->blankForm($this->report);
        // Preselected: who wore Atemschutz
        $this->crewIds = $this->report->crew()->where('breathing_apparatus', true)->pluck('user_id')->map(fn ($id) => (string) $id)->all();
    }

    public function save(ExposureService $service)
    {
        try {
            $count = $service->proposeForCrew(auth()->user(), $this->report, array_map('intval', $this->crewIds), $this->form);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return null;
        }
        session()->flash('expositionen-crew', $count.' Exposition'.($count === 1 ? '' : 'en').' vorgeschlagen – jeder bestätigt seine eigene.');

        return $this->redirectRoute('einsatzbericht.show', $this->report);
    }

    public function render()
    {
        return view('expositionen::livewire.crew-form', [
            'crew' => $this->report->crew()->with('user')->get()->sortBy(fn ($c) => $c->user?->name)->values(),
            'known' => Exposure::where('report_id', $this->report->id)->pluck('user_id')->all(),
        ]);
    }
}

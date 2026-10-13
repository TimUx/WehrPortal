<?php

namespace Modules\Expositionen\src\Livewire;

use Livewire\Component;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Expositionen\src\Models\Exposure;
use Modules\Expositionen\src\Services\ExposureService;

/** Meine Expositionen: proposals to confirm, recent Brand-Einsätze to answer, everything recorded */
class MyExposures extends Component
{
    public function dismiss(int $reportId, ExposureService $service): void
    {
        $service->dismiss(auth()->user(), Report::findOrFail($reportId));
    }

    public function render(ExposureService $service)
    {
        $me = auth()->user();

        return view('expositionen::livewire.my-exposures', [
            'exposures' => Exposure::where('user_id', $me->id)->orderByRaw("status = 'draft' desc")->orderByDesc('occurred_on')->get(),
            'prompts' => $service->openPrompts($me),
        ]);
    }
}

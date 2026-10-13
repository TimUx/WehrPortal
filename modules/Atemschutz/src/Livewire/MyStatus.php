<?php

namespace Modules\Atemschutz\src\Livewire;

use Livewire\Component;
use Modules\Atemschutz\src\Models\TeamMember;
use Modules\Atemschutz\src\Models\Training;
use Modules\Atemschutz\src\Models\Wearer;
use Modules\Atemschutz\src\Services\ReadinessService;

/** Personalakte → Atemschutz: the own deadlines, trainings and Einsätze under Atemschutz */
class MyStatus extends Component
{
    public function render(ReadinessService $readiness)
    {
        $user = auth()->user();
        $wearer = Wearer::where('user_id', $user->id)->first();

        $missions = TeamMember::where('user_id', $user->id)->whereHas('team', fn ($q) => $q->whereNotNull('started_at'))
            ->with('team.operation')->latest('id')->limit(30)->get();
        // Einsätze marked in the Einsatzbericht; those with an own Trupp already stand above
        $watched = $missions->pluck('team.operation.report_id')->filter()->all();

        return view('atemschutz::livewire.my-status', [
            'state' => $wearer ? $readiness->forWearer($wearer) : null,
            'trainings' => Training::where('user_id', $user->id)->latest('done_on')->limit(30)->get(),
            'missions' => $missions,
            'reportMissions' => $readiness->reportMissions([$user->id], 30)->reject(fn ($r) => in_array($r->report_id, $watched, true))->values(),
        ]);
    }
}

<?php

namespace Modules\Personalverwaltung\src\Livewire;

use Livewire\Component;
use Modules\Personalverwaltung\src\Models\HistoryEntry;
use Modules\Personalverwaltung\src\Models\Honor;
use Modules\Personalverwaltung\src\Models\MemberProfile;
use Modules\Personalverwaltung\src\Services\PersonnelService;

/** Personalakte → Laufbahn & Ehrungen: the own career, Ehrungen, Dienstausweis and Dienstbeteiligung */
class OwnFile extends Component
{
    public function render(PersonnelService $personnel)
    {
        $me = auth()->user();
        $personnel->syncHistory($me);
        $year = (int) now('Europe/Berlin')->year;

        return view('personalverwaltung::livewire.own-file', [
            'history' => HistoryEntry::where('user_id', $me->id)->orderByRaw('until is not null')->orderByDesc('since')->orderByDesc('id')->get()->groupBy('kind'),
            'honors' => Honor::where('user_id', $me->id)->orderByDesc('awarded_on')->get(),
            'profile' => MemberProfile::find($me->id),
            'years' => PersonnelService::serviceYears($me),
            'attendance' => PersonnelService::attendanceAvailable()
                ? collect([$year, $year - 1])->mapWithKeys(fn ($y) => [$y => $personnel->attendance($me, $y)]) : collect(),
        ]);
    }
}

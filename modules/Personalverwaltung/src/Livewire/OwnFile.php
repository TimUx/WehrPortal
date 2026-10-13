<?php

namespace Modules\Personalverwaltung\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Personalverwaltung\src\Models\ChangeRequest;
use Modules\Personalverwaltung\src\Models\HistoryEntry;
use Modules\Personalverwaltung\src\Models\Honor;
use Modules\Personalverwaltung\src\Models\MemberProfile;
use Modules\Personalverwaltung\src\Services\PersonnelService;

/** Personalakte → Laufbahn & Ehrungen: the own career, Ehrungen, Dienstausweis and Dienstbeteiligung */
class OwnFile extends Component
{
    /** Änderungswunsch: what is wrong, sent to the Personalverwalter */
    public string $topic = 'laufbahn';

    public string $requestText = '';

    public ?string $flash = null;

    public function requestChange(PersonnelService $personnel): void
    {
        $this->resetErrorBag();
        try {
            $personnel->requestChange(auth()->user(), $this->topic, $this->requestText);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }
        $this->requestText = '';
        $this->flash = 'Danke! Dein Änderungswunsch ist bei der Personalverwaltung.';
    }

    public function render(PersonnelService $personnel)
    {
        $me = auth()->user();
        $personnel->syncHistory($me);
        $year = (int) now('Europe/Berlin')->year;

        return view('personalverwaltung::livewire.own-file', [
            'history' => HistoryEntry::where('user_id', $me->id)->orderByRaw('until is not null')->orderByDesc('since')->orderByDesc('id')->get()->groupBy('kind'),
            'honors' => Honor::where('user_id', $me->id)->orderByDesc('awarded_on')->get(),
            'profile' => MemberProfile::find($me->id),
            'requests' => ChangeRequest::where('user_id', $me->id)->latest()->limit(10)->get(),
            'years' => PersonnelService::serviceYears($me),
            'attendance' => PersonnelService::attendanceAvailable()
                ? collect([$year, $year - 1])->mapWithKeys(fn ($y) => [$y => $personnel->attendance($me, $y)]) : collect(),
        ]);
    }
}

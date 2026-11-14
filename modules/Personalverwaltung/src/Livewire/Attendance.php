<?php

namespace Modules\Personalverwaltung\src\Livewire;

use App\Models\User;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Kalender\src\Models\Event;
use Modules\Personalverwaltung\src\Services\PersonnelService;

/** Dienstbeteiligung of the year from the Kalender attendance, best first; also as CSV */
class Attendance extends Component
{
    #[Url(as: 'jahr')]
    public int $year = 0;

    /** @var list<string> */
    #[Url(as: 'arten')]
    public array $types = PersonnelService::DUTY_TYPES;

    public function mount(): void
    {
        $this->year = $this->year ?: (int) now('Europe/Berlin')->year;
    }

    public function render(PersonnelService $personnel)
    {
        $types = array_values(array_intersect($this->types, array_keys(Event::TYPES)));
        $manager = auth()->user();
        $members = PersonnelService::visibleMembers($manager)->where('status', 'active')->orderBy('name')->get();
        $attendance = $personnel->attendanceOf($members, $this->year, $types);
        PersonnelService::loadVisibleGroups($manager, $members);
        $rows = $members
            ->map(fn (User $member) => ['user' => $member, ...$attendance[$member->id]])
            ->sortByDesc(fn ($r) => [$r['percent'] ?? -1, $r['attended']])->values();

        return view('personalverwaltung::livewire.attendance', [
            'rows' => $rows,
            'typeOptions' => Event::TYPES,
            'average' => $rows->whereNotNull('percent')->avg('percent'),
            'csvTypes' => $types,
        ]);
    }
}

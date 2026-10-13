<?php

namespace Modules\Personalverwaltung\src\Livewire;

use App\Livewire\Profile\Hours;
use App\Services\HoursRegistry;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Personalverwaltung\src\Services\PersonnelService;

/** Personalverwaltung → Stunden: the Stundennachweis of every Kamerad of a year, by source */
class HoursOverview extends Component
{
    #[Url(as: 'jahr')]
    public int $year = 0;

    public function boot(): void
    {
        abort_unless(PersonnelService::canManage(auth()->user()), 403);
    }

    public function mount(): void
    {
        $this->year = $this->year ?: (int) now('Europe/Berlin')->year;
    }

    public function render(HoursRegistry $hours)
    {
        $members = PersonnelService::visibleMembers(auth()->user())->where('status', 'active')->orderBy('name')->get(['id', 'name']);
        $rows = $hours->rows($members->pluck('id')->all(), ...Hours::period($this->year));
        $byUser = $rows->groupBy('user_id')->map(fn ($r) => $r->groupBy('source')->map->sum('minutes'));

        return view('personalverwaltung::livewire.hours-overview', [
            'members' => $members->sortByDesc(fn ($m) => ($byUser[$m->id] ?? collect())->sum())->values(),
            'byUser' => $byUser,
            'sources' => $hours->sources(),
            'total' => $rows->sum('minutes'),
        ]);
    }
}

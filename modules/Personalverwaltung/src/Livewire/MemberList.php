<?php

namespace Modules\Personalverwaltung\src\Livewire;

use App\Models\Group;
use App\Models\User;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Personalverwaltung\src\Services\PersonnelService;

/** The Kameraden a Personalverwalter may see: Abteilung, Dienstgrad, Dienstjahre, Dienstbeteiligung, due Ehrungen */
class MemberList extends Component
{
    use WithPagination;

    #[Url(as: 'suche')]
    public string $search = '';

    #[Url(as: 'abteilung')]
    public string $groupId = '';

    #[Url(as: 'status')]
    public string $status = 'active';

    public function updating(string $name): void
    {
        if (in_array($name, ['search', 'groupId', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function render(PersonnelService $personnel)
    {
        $manager = auth()->user();
        $year = (int) now('Europe/Berlin')->year;
        $term = trim($this->search);
        $members = PersonnelService::visibleMembers($manager)->with('groups')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'ilike', '%'.$term.'%')->orWhere('email', 'ilike', '%'.$term.'%')))
            ->when($this->groupId !== '', fn ($q) => $q->whereHas('groups', fn ($g) => $g->where('groups.id', (int) $this->groupId)))
            ->when(in_array($this->status, ['active', 'inactive', 'left'], true), fn ($q) => $q->where('status', $this->status))
            ->orderBy('name')->paginate(50);

        $scope = PersonnelService::scopeOf($manager);
        $withAttendance = PersonnelService::attendanceAvailable();

        return view('personalverwaltung::livewire.member-list', [
            'members' => $members,
            'groups' => Group::when($scope !== [], fn ($q) => $q->whereIn('id', $scope))->orderBy('sort_order')->orderBy('name')->pluck('name', 'id'),
            'year' => $year,
            'attendance' => $withAttendance ? $members->getCollection()->mapWithKeys(fn (User $m) => [$m->id => $personnel->attendance($m, $year)]) : collect(),
            'due' => $personnel->dueHonors($manager, $year)->groupBy(fn ($row) => $row['user']->id),
            'withAttendance' => $withAttendance,
            'scoped' => $scope !== [],
        ]);
    }
}

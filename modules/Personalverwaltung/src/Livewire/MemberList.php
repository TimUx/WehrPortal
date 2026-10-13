<?php

namespace Modules\Personalverwaltung\src\Livewire;

use App\Models\Group;
use App\Models\MemberFunction;
use App\Models\User;
use App\Support\FireRanks;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Personalverwaltung\src\Models\HonorType;
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

    /** Sammelverarbeitung: the marked Kameraden and what happens to them */
    public array $selected = [];

    public string $bulkAction = 'promote';

    /** '' = one step up, otherwise the step of the Dienstgrad */
    public string $bulkStep = '';

    public string $bulkDate = '';

    public string $bulkHonorType = '';

    public string $bulkHonorTitle = '';

    public string $bulkFunction = '';

    public ?string $flash = null;

    public function mount(): void
    {
        $this->bulkDate = PersonnelService::today()->toDateString();
    }

    public function applyBulk(PersonnelService $personnel): void
    {
        $this->resetErrorBag();
        $this->flash = null;
        try {
            $result = match ($this->bulkAction) {
                'promote' => $personnel->promote(auth()->user(), $this->selected, $this->bulkStep !== '' ? (int) $this->bulkStep : null, $this->bulkDate),
                'honor' => $personnel->awardMany(auth()->user(), $this->selected, ['honor_type_id' => $this->bulkHonorType ?: null, 'title' => $this->bulkHonorTitle, 'awarded_on' => $this->bulkDate]),
                'function_add', 'function_remove' => $personnel->setFunction(auth()->user(), $this->selected, (int) $this->bulkFunction, $this->bulkAction === 'function_add'),
                default => ['done' => [], 'skipped' => []],
            };
        } catch (ValidationException $e) {
            $this->addError('bulk', collect($e->errors())->flatten()->first());

            return;
        }
        $this->flash = count($result['done']).' erledigt'.($result['done'] ? ': '.implode(', ', $result['done']) : '')
            .($result['skipped'] ? ' · übersprungen (schon so oder nicht möglich): '.implode(', ', $result['skipped']) : '').'.';
        $this->selected = [];
    }

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
        $members = PersonnelService::visibleMembers($manager)->with(['groups', 'functions.parent'])
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
            'canEdit' => PersonnelService::canManage($manager),
            'steps' => collect(range(0, FireRanks::maxStep()))->mapWithKeys(fn ($s) => [$s => FireRanks::label($s)]),
            'honorTypes' => HonorType::orderBy('sort_order')->orderBy('name')->pluck('name', 'id'),
            'functions' => MemberFunction::assignable()->with('parent')->get()->mapWithKeys(fn (MemberFunction $f) => [$f->id => $f->fullLabel()])->sort(),
        ]);
    }
}

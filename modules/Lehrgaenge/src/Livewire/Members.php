<?php

namespace Modules\Lehrgaenge\src\Livewire;

use App\Models\Group;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Lehrgaenge\src\Models\Course;
use Modules\Lehrgaenge\src\Models\Record;
use Modules\Lehrgaenge\src\Services\RegistrationService;

/**
 * Ausbildungsleiter and Wehrführung: every Kamerad with the number of absolvierte Lehrgänge. Admins and
 * Ausbildungsleiter enter one Lehrgang for many Kameraden at once, the date optional (old Lehrgänge).
 */
class Members extends Component
{
    public string $search = '';

    /** Nachtragen für mehrere: Lehrgang, date (optional), note, Abteilung filter and the chosen Kameraden */
    public string $bulkCourse = '';

    public string $bulkDate = '';

    public string $bulkNote = '';

    public string $bulkGroup = '';

    /** @var list<string> */
    public array $bulkMembers = [];

    public ?string $flash = null;

    public function boot(): void
    {
        abort_unless(auth()->user()?->canViewLehrgaenge(), 403);
    }

    /** Ticks every Kamerad of the list (filtered by Abteilung) who doesn't have the Lehrgang yet */
    public function selectAll(): void
    {
        abort_unless(auth()->user()->canManageLehrgaenge(), 403);
        $this->bulkMembers = $this->bulkCandidates()->reject(fn (User $m) => $m->getAttribute('has_course'))->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function addMany(RegistrationService $registrations): void
    {
        abort_unless(auth()->user()->canManageLehrgaenge(), 403);
        $this->resetErrorBag();
        $this->flash = null;
        try {
            $result = $registrations->recordCompletedMany(auth()->user(), $this->bulkCourse, array_map('intval', $this->bulkMembers), $this->bulkDate, $this->bulkNote);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }
        $this->flash = (count($result['added']) === 1 ? '1 Kamerad' : count($result['added']).' Kameraden').' nachgetragen'
            .($result['skipped'] ? '. Hatten den Lehrgang schon: '.implode(', ', $result['skipped']) : '').'.';
        $this->reset('bulkMembers');
    }

    /** Active Kameraden for the bulk entry; has_course marks who already has the chosen Lehrgang */
    private function bulkCandidates()
    {
        $course = trim($this->bulkCourse) === '' ? null : Course::whereRaw('lower(name) = ?', [mb_strtolower(trim(preg_replace('/\s+/u', ' ', $this->bulkCourse)))])->first();
        $done = $course ? Record::where('course_id', $course->id)->where('status', 'completed')->pluck('user_id')->flip() : collect();

        return User::active()->orderBy('name')
            ->when($this->bulkGroup !== '', fn ($q) => $q->whereHas('groups', fn ($g) => $g->whereKey((int) $this->bulkGroup)))
            ->get(['id', 'name'])
            ->each(fn (User $m) => $m->setAttribute('has_course', $done->has($m->id)));
    }

    public function render()
    {
        $term = trim($this->search);
        $canManage = auth()->user()->canManageLehrgaenge();

        return view('lehrgaenge::livewire.members', [
            'members' => User::active()->orderBy('name')
                ->when($term !== '', fn ($q) => $q->where('name', 'ilike', '%'.$term.'%'))
                ->select('users.*')
                ->addSelect(['completed' => Record::selectRaw('count(*)')->whereColumn('lq_records.user_id', 'users.id')->where('status', 'completed')])
                ->get(),
            'canManage' => $canManage,
            'candidates' => $canManage && trim($this->bulkCourse) !== '' ? $this->bulkCandidates() : collect(),
            'courseNames' => $canManage ? Course::orderBy('name')->pluck('name') : collect(),
            'groups' => $canManage ? Group::orderBy('sort_order')->orderBy('name')->pluck('name', 'id') : collect(),
        ]);
    }
}

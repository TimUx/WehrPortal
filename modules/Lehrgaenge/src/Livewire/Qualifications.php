<?php

namespace Modules\Lehrgaenge\src\Livewire;

use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Lehrgaenge\src\Models\Course;
use Modules\Lehrgaenge\src\Models\Record;

/** Ausbildungsstand: who has which Lehrgang – Kameraden × Lehrgänge, with refreshers that are due */
class Qualifications extends Component
{
    #[Url(as: 'abteilung')]
    public string $groupId = '';

    public function boot(): void
    {
        abort_unless(auth()->user()?->canViewLehrgaenge(), 403);
    }

    /** @return array{members: Collection, courses: Collection, cells: Collection} */
    public static function table(?int $groupId = null): array
    {
        $members = User::active()->when($groupId, fn ($q) => $q->whereHas('groups', fn ($g) => $g->whereKey($groupId)))->orderBy('name')->get(['id', 'name']);
        $records = Record::with('course')->where('status', 'completed')->whereIn('user_id', $members->pluck('id'))->get();
        $courses = Course::whereIn('id', $records->pluck('course_id')->unique())->orderBy('name')->get();

        return ['members' => $members, 'courses' => $courses, 'cells' => $records->keyBy(fn (Record $r) => $r->user_id.'-'.$r->course_id)];
    }

    public function render()
    {
        return view('lehrgaenge::livewire.qualifications', [
            ...self::table($this->groupId !== '' ? (int) $this->groupId : null),
            'groups' => Group::options(),
        ]);
    }
}

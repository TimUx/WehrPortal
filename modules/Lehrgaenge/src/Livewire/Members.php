<?php

namespace Modules\Lehrgaenge\src\Livewire;

use App\Models\User;
use Livewire\Component;
use Modules\Lehrgaenge\src\Models\Record;

/** Ausbildungsleiter and Wehrführung: every Kamerad with the number of absolvierte Lehrgänge */
class Members extends Component
{
    public string $search = '';

    public function boot(): void
    {
        abort_unless(auth()->user()?->canViewLehrgaenge(), 403);
    }

    public function render()
    {
        $term = trim($this->search);

        return view('lehrgaenge::livewire.members', [
            'members' => User::active()->orderBy('name')
                ->when($term !== '', fn ($q) => $q->where('name', 'ilike', '%'.$term.'%'))
                ->select('users.*')
                ->addSelect(['completed' => Record::selectRaw('count(*)')->whereColumn('lq_records.user_id', 'users.id')->where('status', 'completed')])
                ->get(),
        ]);
    }
}

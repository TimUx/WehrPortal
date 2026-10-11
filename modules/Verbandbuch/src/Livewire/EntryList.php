<?php

namespace Modules\Verbandbuch\src\Livewire;

use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Verbandbuch\src\Models\Entry;
use Modules\Verbandbuch\src\Services\VerbandbuchService;

/**
 * The entries: all of them for Sicherheitsbeauftragte, Wehrführung and admins, with filters;
 * for everyone else the ones they are part of. $personal: only those where the Kamerad was hurt
 * (Personalakte).
 */
class EntryList extends Component
{
    use WithPagination;

    public bool $personal = false;

    #[Url(as: 'jahr')]
    public ?int $year = null;

    #[Url(as: 'art')]
    public string $kind = '';

    #[Url]
    public string $status = '';

    #[Url(as: 'suche')]
    public string $search = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $user = auth()->user();
        $all = ! $this->personal && VerbandbuchService::canManage($user);
        $term = trim($this->search);

        $query = Entry::query()
            ->when($this->personal, fn ($q) => $q->where('injured_user_id', $user->id))
            ->when(! $this->personal && ! $all, fn ($q) => $q->involving($user))
            ->when($all && $this->status === 'report', fn ($q) => $q->awaitingReport())
            ->when($all && $this->status !== 'report' && $this->year, fn ($q) => $q->where('year', $this->year))
            ->when($all && $this->status === 'voided', fn ($q) => $q->whereNotNull('voided_at'))
            ->when($all && $this->kind !== '', fn ($q) => $q->where('kind', $this->kind))
            // Health data is encrypted; names and places can be searched
            ->when($all && $term !== '', fn ($q) => $q->where(fn ($s) => $s->where('injured_name', 'ilike', '%'.$term.'%')
                ->orWhere('location', 'ilike', '%'.$term.'%')->orWhere('first_aider_name', 'ilike', '%'.$term.'%')
                ->orWhere('recorded_by_name', 'ilike', '%'.$term.'%')));

        return view('verbandbuch::livewire.entry-list', [
            'entries' => $query->orderByDesc('occurred_at')->paginate(50),
            'all' => $all,
            'years' => $all ? Entry::query()->distinct()->orderByDesc('year')->pluck('year') : collect(),
            'awaiting' => $all ? Entry::awaitingReport()->count() : 0,
        ]);
    }
}

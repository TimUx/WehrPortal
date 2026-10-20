<?php

namespace Modules\Finanzen\src\Livewire;

use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Finanzen\src\Models\CostCenter;
use Modules\Finanzen\src\Models\Entry;
use Modules\Finanzen\src\Services\FinanceService;

/** All Buchungen of a year for Kassenwart and Wehrführung, with filters and sums */
class EntryList extends Component
{
    use WithPagination;

    #[Url(as: 'jahr')]
    public ?int $year = null;

    #[Url]
    public string $status = '';

    #[Url(as: 'art')]
    public string $kind = '';

    #[Url(as: 'kostenstelle')]
    public string $center = '';

    #[Url(as: 'suche')]
    public string $search = '';

    public function boot(): void
    {
        abort_unless(FinanceService::canReview(auth()->user()), 403);
    }

    public function mount(): void
    {
        $this->year ??= FinanceService::today()->year;
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function render(FinanceService $finance)
    {
        $term = trim($this->search);
        $query = Entry::query()
            // Waiting ones of every year, everything else of the chosen year
            ->when($this->status === 'submitted', fn ($q) => $q->where('status', 'submitted'), fn ($q) => $q->where('year', $this->year)
                ->when($this->status !== '', fn ($q) => $q->where('status', $this->status)))
            ->when($this->kind !== '', fn ($q) => $q->where('kind', $this->kind))
            ->when($this->center !== '', fn ($q) => $q->where('cost_center_id', (int) $this->center))
            ->when($term !== '', fn ($q) => $q->where(fn ($s) => $s->where('title', 'ilike', '%'.$term.'%')->orWhere('vendor', 'ilike', '%'.$term.'%')
                ->orWhere('description', 'ilike', '%'.$term.'%')->orWhereHas('submitter', fn ($u) => $u->where('name', 'ilike', '%'.$term.'%'))));
        $booked = (clone $query)->whereIn('status', Entry::BOOKED);

        return view('finanzen::livewire.entry-list', [
            'entries' => (clone $query)->with(['costCenter', 'submitter'])->withCount('receipts')->orderByDesc('booked_on')->orderByDesc('number')->paginate(50),
            'sumExpense' => (int) (clone $booked)->where('kind', 'expense')->sum('amount_cents'),
            'sumIncome' => (int) (clone $booked)->where('kind', 'income')->sum('amount_cents'),
            'centers' => CostCenter::ordered()->get(),
            'years' => $finance->years(),
            'waiting' => Entry::where('status', 'submitted')->count(),
            'canManage' => FinanceService::canManage(auth()->user()),
            'reportOn' => FinanceService::reportEnabled(),
        ]);
    }
}

<?php

namespace Modules\Finanzen\src\Livewire;

use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Finanzen\src\Models\Entry;
use Modules\Finanzen\src\Services\FinanceService;

/**
 * The own Ausgaben with their status for every Kamerad. Kassenwart, Wehrführung and admins (or everyone,
 * when the Kassenwart makes it public) also see the status of every Kostenstelle – with the budget when
 * it is switched on – and the approved and paid Ausgaben of the year (without Belege).
 */
class Overview extends Component
{
    #[Url(as: 'jahr')]
    public ?int $year = null;

    public function mount(): void
    {
        $this->year ??= FinanceService::today()->year;
    }

    public function render(FinanceService $finance)
    {
        $user = auth()->user();
        $full = FinanceService::canSeeOverview($user);
        $overview = $full ? $finance->overview($this->year, activeOnly: true)->filter(fn ($row) => $row['budget'] > 0 || $row['approved'] + $row['paid'] + $row['income'] > 0 || $row['center']->is_active) : collect();

        return view('finanzen::livewire.overview', [
            'overview' => $overview,
            'totals' => $finance->totals($overview),
            'years' => $finance->years(),
            'closed' => FinanceService::isClosed($this->year),
            'mine' => Entry::with('costCenter')->where('submitted_by', $user->id)->where('kind', 'expense')
                ->where(fn ($q) => $q->where('year', $this->year)->orWhereIn('status', ['submitted', 'approved']))
                ->latest('id')->get(),
            'full' => $full,
            'budgetOn' => FinanceService::budgetEnabled(),
            'spent' => ! $full ? collect() : Entry::with('costCenter')->where('year', $this->year)->where('kind', 'expense')->whereIn('status', Entry::BOOKED)
                ->orderByDesc('booked_on')->orderByDesc('number')->get(),
            'toReview' => FinanceService::canReview($user) ? Entry::where('status', 'submitted')->count() : 0,
            'canReview' => FinanceService::canReview($user),
            'canManage' => FinanceService::canManage($user),
        ]);
    }
}

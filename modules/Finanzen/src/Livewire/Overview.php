<?php

namespace Modules\Finanzen\src\Livewire;

use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Finanzen\src\Models\Entry;
use Modules\Finanzen\src\Services\FinanceService;

/**
 * For the whole Mannschaft: the budget status of every Kostenstelle, the approved and paid Ausgaben
 * of the year (without Belege), and the own Ausgaben with their status.
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
        $overview = $finance->overview($this->year, activeOnly: true)->filter(fn ($row) => $row['budget'] > 0 || $row['approved'] + $row['paid'] + $row['income'] > 0 || $row['center']->is_active);

        return view('finanzen::livewire.overview', [
            'overview' => $overview,
            'totals' => $finance->totals($overview),
            'years' => $finance->years(),
            'closed' => FinanceService::isClosed($this->year),
            'mine' => Entry::with('costCenter')->where('submitted_by', $user->id)->where('kind', 'expense')
                ->where(fn ($q) => $q->where('year', $this->year)->orWhereIn('status', ['submitted', 'approved']))
                ->latest('id')->get(),
            'spent' => Entry::with('costCenter')->where('year', $this->year)->where('kind', 'expense')->whereIn('status', Entry::BOOKED)
                ->orderByDesc('booked_on')->orderByDesc('number')->get(),
            'toReview' => FinanceService::canReview($user) ? Entry::where('status', 'submitted')->count() : 0,
            'canReview' => FinanceService::canReview($user),
            'canManage' => FinanceService::canManage($user),
        ]);
    }
}

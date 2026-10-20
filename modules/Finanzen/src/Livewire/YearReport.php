<?php

namespace Modules\Finanzen\src\Livewire;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Finanzen\src\Models\Entry;
use Modules\Finanzen\src\Services\FinanceService;

/** Jahresabschluss: budget against actual per Kostenstelle, Einnahmen and Saldo, export, closing the year */
class YearReport extends Component
{
    #[Url(as: 'jahr')]
    public ?int $year = null;

    public ?string $flash = null;

    public function boot(): void
    {
        abort_unless(FinanceService::canReview(auth()->user()), 403);
        abort_unless(FinanceService::reportEnabled(), 404);
    }

    public function mount(): void
    {
        $this->year ??= FinanceService::today()->year;
    }

    public function close(FinanceService $finance): void
    {
        $this->attempt(fn () => $finance->closeYear(auth()->user(), $this->year), 'Das Jahr '.$this->year.' ist abgeschlossen. Buchungen und Budgets sind gesperrt.');
    }

    public function reopen(FinanceService $finance): void
    {
        $this->attempt(fn () => $finance->reopenYear(auth()->user(), $this->year), 'Das Jahr '.$this->year.' ist wieder offen.');
    }

    private function attempt(callable $action, string $done): void
    {
        $this->resetErrorBag();

        try {
            $action();
            $this->flash = $done;
        } catch (ValidationException $e) {
            $this->addError('year', collect($e->errors())->flatten()->first());
            $this->flash = null;
        }
    }

    public function render(FinanceService $finance)
    {
        $overview = $finance->overview($this->year, activeOnly: true);

        return view('finanzen::livewire.year-report', [
            'overview' => $overview,
            'totals' => $finance->totals($overview),
            'years' => $finance->years(),
            'closedAt' => DB::table('fin_closed_years')->leftJoin('users', 'users.id', '=', 'fin_closed_years.closed_by')
                ->where('year', $this->year)->first(['closed_at', 'users.name']),
            'open' => Entry::where('year', $this->year)->whereIn('status', ['submitted', 'approved'])->count(),
            'canManage' => FinanceService::canManage(auth()->user()),
            'budgetOn' => FinanceService::budgetEnabled(),
        ]);
    }
}

<?php

namespace Modules\Finanzen\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Finanzen\src\Models\Budget;
use Modules\Finanzen\src\Models\CostCenter;
use Modules\Finanzen\src\Services\FinanceService;
use Modules\Finanzen\src\Support\Money;

/** The Kassenwart plans the budget per Kostenstelle and year and keeps the Kostenstellen */
class BudgetPlan extends Component
{
    #[Url(as: 'jahr')]
    public ?int $year = null;

    /** @var array<int, string> budget per Kostenstelle as typed */
    public array $amounts = [];

    /** @var array{name: string, code: string, description: string, is_active: bool} */
    public array $center = ['name' => '', 'code' => '', 'description' => '', 'is_active' => true];

    public ?int $editingId = null;

    public ?string $flash = null;

    public function boot(): void
    {
        abort_unless(FinanceService::canManage(auth()->user()), 403);
    }

    public function mount(): void
    {
        $this->year ??= FinanceService::today()->year;
        $this->loadAmounts();
    }

    public function updatedYear(): void
    {
        $this->loadAmounts();
        $this->flash = null;
    }

    private function loadAmounts(): void
    {
        $budgets = Budget::where('year', $this->year)->pluck('amount_cents', 'cost_center_id');
        $this->amounts = CostCenter::ordered()->get()->mapWithKeys(fn (CostCenter $c) => [$c->id => isset($budgets[$c->id]) ? Money::input($budgets[$c->id]) : ''])->all();
    }

    public function saveBudgets(FinanceService $finance): void
    {
        $this->resetErrorBag();
        $saved = 0;

        foreach ($this->amounts as $id => $typed) {
            if (trim($typed) === '') {
                continue;
            }

            try {
                $finance->setBudget(auth()->user(), CostCenter::findOrFail($id), $this->year, Money::parse($typed));
                $saved++;
            } catch (ValidationException $e) {
                $this->addError('amounts.'.$id, collect($e->errors())->flatten()->first());
            }
        }

        $this->flash = $this->getErrorBag()->isEmpty() ? 'Budget '.$this->year.' gespeichert.' : null;
        $this->loadAmounts();
    }

    public function copyPrevious(FinanceService $finance): void
    {
        $this->attempt(function () use ($finance) {
            $count = $finance->copyBudgets(auth()->user(), $this->year);
            $this->flash = $count ? $count.' Budget'.($count === 1 ? '' : 's').' aus '.($this->year - 1).' übernommen.' : 'Nichts zu übernehmen: '.($this->year - 1).' hat keine Budgets, oder alle sind schon gesetzt.';
            $this->loadAmounts();
        });
    }

    public function edit(int $id): void
    {
        $c = CostCenter::findOrFail($id);
        $this->editingId = $c->id;
        $this->center = ['name' => $c->name, 'code' => (string) $c->code, 'description' => (string) $c->description, 'is_active' => $c->is_active];
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'center');
        $this->resetErrorBag();
    }

    public function saveCenter(FinanceService $finance): void
    {
        $this->attempt(function () use ($finance) {
            $center = $finance->saveCostCenter(auth()->user(), $this->editingId ? CostCenter::findOrFail($this->editingId) : null, $this->center);
            $this->flash = '„'.$center->name.'“ gespeichert.';
            $this->cancelEdit();
            $this->loadAmounts();
        });
    }

    public function deleteCenter(int $id, FinanceService $finance): void
    {
        $this->attempt(function () use ($id, $finance) {
            $center = CostCenter::findOrFail($id);
            $finance->deleteCostCenter(auth()->user(), $center);
            $this->flash = '„'.$center->name.'“ gelöscht.';
            $this->loadAmounts();
        });
    }

    private function attempt(callable $action): void
    {
        $this->resetErrorBag();
        $this->flash = null;

        try {
            $action();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
        }
    }

    public function render(FinanceService $finance)
    {
        return view('finanzen::livewire.budget-plan', [
            'overview' => $finance->overview($this->year)->keyBy(fn ($row) => $row['center']->id),
            'centers' => CostCenter::ordered()->withCount('entries')->get(),
            'years' => collect($finance->years())->push(FinanceService::today()->year + 1)->unique()->sortDesc()->values(),
            'closed' => FinanceService::isClosed($this->year),
        ]);
    }
}

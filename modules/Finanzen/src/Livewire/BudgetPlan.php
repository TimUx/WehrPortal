<?php

namespace Modules\Finanzen\src\Livewire;

use App\Support\Money;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Finanzen\src\Models\Budget;
use Modules\Finanzen\src\Models\CostCenter;
use Modules\Finanzen\src\Services\FinanceService;

/** The Kassenwart plans the budget per Kostenstelle and year, keeps the Kostenstellen and chooses what the Wehr uses */
class BudgetPlan extends Component
{
    #[Url(as: 'jahr')]
    public ?int $year = null;

    public string $locationId = '';

    /** @var array<int, string> budget per Kostenstelle as typed */
    public array $amounts = [];

    /** @var array{name: string, code: string, description: string, is_active: bool} */
    public array $center = ['name' => '', 'code' => '', 'description' => '', 'is_active' => true];

    public ?int $editingId = null;

    public ?string $flash = null;

    /** @var array{budget: bool, report: bool, public: bool} */
    public array $settings = ['budget' => true, 'report' => true, 'public' => false];

    public function boot(): void
    {
        abort_unless(FinanceService::canManageBudgetPage(auth()->user()), 403);
    }

    public function mount(): void
    {
        $this->year ??= FinanceService::today()->year;
        $this->settings = FinanceService::settings();
        if (FinanceService::locationsEnabled()) {
            $locations = FinanceService::budgetLocationOptions(auth()->user());
            $preferred = FinanceService::defaultEntryLocation(auth()->user());
            $this->locationId = $locations->contains('id', $preferred)
                ? (string) $preferred
                : ($locations->count() === 1 ? (string) $locations->first()->id : '');
        }
        $this->loadAmounts();
    }

    public function updatedYear(): void
    {
        if (FinanceService::locationsEnabled() && $this->locationId === 'legacy'
            && ! Budget::where('year', $this->year)->whereNull('location_id')->exists()) {
            $this->locationId = '';
        }
        if (FinanceService::locationsEnabled() && ctype_digit($this->locationId)
            && ! FinanceService::budgetLocationOptions(auth()->user())->contains('id', (int) $this->locationId)) {
            $this->locationId = '';
        }
        $this->loadAmounts();
        $this->flash = null;
    }

    public function updatedLocationId(): void
    {
        $this->assertLocationSelection();
        $this->loadAmounts();
        $this->flash = null;
    }

    private function loadAmounts(): void
    {
        $query = Budget::where('year', $this->year);
        if (FinanceService::locationsEnabled()) {
            if ($this->locationId === 'legacy') {
                $query->whereNull('location_id');
            } elseif ($this->locationId !== '') {
                $query->where('location_id', $this->selectedLocationId());
            } else {
                $this->amounts = CostCenter::ordered()->get()->mapWithKeys(fn (CostCenter $c) => [$c->id => ''])->all();

                return;
            }
        }
        $budgets = $query->pluck('amount_cents', 'cost_center_id');
        $this->amounts = CostCenter::ordered()->get()->mapWithKeys(fn (CostCenter $c) => [$c->id => isset($budgets[$c->id]) ? Money::input($budgets[$c->id]) : ''])->all();
    }

    public function saveBudgets(FinanceService $finance): void
    {
        $this->resetErrorBag();
        $this->assertLocationSelection();
        if (FinanceService::locationsEnabled() && $this->locationId === '') {
            $this->addError('locationId', 'Bitte zuerst einen Standort auswählen.');

            return;
        }
        $saved = 0;

        foreach ($this->amounts as $id => $typed) {
            if (trim($typed) === '') {
                continue;
            }

            try {
                $finance->setBudget(auth()->user(), CostCenter::findOrFail($id), $this->year, Money::parse($typed), locationId: $this->selectedLocationId());
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
        $this->assertLocationSelection();
        if (FinanceService::locationsEnabled() && ($this->locationId === '' || $this->locationId === 'legacy')) {
            $this->addError('locationId', 'Bitte einen aktiven Standort auswählen.');

            return;
        }

        $this->attempt(function () use ($finance) {
            $count = $finance->copyBudgets(auth()->user(), $this->year, $this->selectedLocationId());
            $this->flash = $count ? $count.' Budget'.($count === 1 ? '' : 's').' aus '.($this->year - 1).' übernommen.' : 'Nichts zu übernehmen: '.($this->year - 1).' hat keine Budgets, oder alle sind schon gesetzt.';
            $this->loadAmounts();
        });
    }

    public function saveSettings(FinanceService $finance): void
    {
        $this->attempt(function () use ($finance) {
            $finance->saveSettings(auth()->user(), $this->settings);
            $this->settings = FinanceService::settings();
            $this->flash = 'Einstellungen gespeichert.';
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

    private function assertLocationSelection(): void
    {
        if (! FinanceService::locationsEnabled() || $this->locationId === '') {
            return;
        }

        if ($this->locationId === 'legacy') {
            abort_unless(FinanceService::canManage(auth()->user())
                && Budget::where('year', $this->year)->whereNull('location_id')->exists(), 403);

            return;
        }

        $locationId = $this->selectedLocationId();
        abort_unless($locationId !== null && FinanceService::budgetLocationOptions(auth()->user())->contains('id', $locationId), 403);
    }

    private function selectedLocationId(): ?int
    {
        return ctype_digit($this->locationId) && (int) $this->locationId > 0 ? (int) $this->locationId : null;
    }

    public function render(FinanceService $finance)
    {
        $this->assertLocationSelection();
        $locationId = FinanceService::locationsEnabled() ? $this->selectedLocationId() : null;
        $overview = FinanceService::locationsEnabled() && $this->locationId === ''
            ? collect()
            : $finance->overview($this->year, locationId: $locationId, unassignedLocation: $this->locationId === 'legacy')->keyBy(fn ($row) => $row['center']->id);
        $centers = CostCenter::ordered()->withCount(['entries' => function ($query) use ($locationId) {
            if (FinanceService::locationsEnabled()) {
                if ($this->locationId === 'legacy') {
                    $query->whereNull('location_id');
                } elseif ($locationId !== null) {
                    $query->where('location_id', $locationId);
                } elseif (! FinanceService::canReviewGlobally(auth()->user())) {
                    $query->whereRaw('1 = 0');
                }
            }
        }])->get();

        return view('finanzen::livewire.budget-plan', [
            'overview' => $overview,
            'centers' => $centers,
            'years' => collect($finance->years())->push(FinanceService::today()->year + 1)->unique()->sortDesc()->values(),
            'closed' => FinanceService::isClosed($this->year),
            'budgetOn' => FinanceService::budgetEnabled(),
            'canManage' => FinanceService::canManage(auth()->user()),
            'locationsEnabled' => FinanceService::locationsEnabled(),
            'locations' => FinanceService::budgetLocationOptions(auth()->user()),
            'hasLegacyBudgets' => FinanceService::locationsEnabled() && FinanceService::canManage(auth()->user())
                && Budget::where('year', $this->year)->whereNull('location_id')->exists(),
        ]);
    }
}

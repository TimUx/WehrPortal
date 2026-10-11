<?php

namespace Modules\Wartung\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Ausruestung\src\Models\Category;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Wartung\src\Models\Task;
use Modules\Wartung\src\Services\WartungService;

/** The open Wartungen (overdue first), the year in numbers and devices whose lifetime runs out */
class Overview extends Component
{
    use WithPagination;

    /** Lifetime ending within this many days shows up here */
    public const LIFETIME_DAYS = 180;

    #[Url]
    public string $filter = '';

    #[Url(as: 'kategorie')]
    public string $category = '';

    #[Url(as: 'suche')]
    public string $search = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    public function cancelTask(int $id, WartungService $service): void
    {
        try {
            $service->cancelTask(auth()->user(), Task::findOrFail($id));
        } catch (ValidationException $e) {
            $this->addError('wartung', collect($e->errors())->flatten()->first());
        }
    }

    public function render(WartungService $service)
    {
        $user = auth()->user();
        $term = trim($this->search);
        $today = WartungService::today();

        $tasks = Task::open()->with(['template', 'equipment.category', 'provider'])
            ->when($this->filter === 'overdue', fn ($q) => $q->where('due_on', '<', $today->toDateString()))
            ->when($this->filter === 'external', fn ($q) => $q->where(fn ($q) => $q->whereNotNull('provider_id')->orWhereHas('template', fn ($t) => $t->where('performer', 'external'))))
            ->when($this->category !== '', fn ($q) => $q->whereHas('equipment', fn ($e) => $e->where('category_id', (int) $this->category)))
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->whereHas('equipment', fn ($e) => $e->where('name', 'ilike', '%'.$term.'%')->orWhere('code', 'ilike', '%'.$term.'%'))
                ->orWhereHas('template', fn ($t) => $t->where('name', 'ilike', '%'.$term.'%'))))
            ->orderBy('due_on')->paginate(50);

        $limit = $today->copy()->addDays(self::LIFETIME_DAYS);

        return view('wartung::livewire.overview', [
            'tasks' => $tasks,
            'overdue' => Task::overdue()->count(),
            'open' => Task::open()->count(),
            'year' => $service->yearTotals($today->year),
            'categories' => Category::orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'lifetime' => Equipment::inService()->with('category')->get()
                ->filter(fn (Equipment $e) => $e->lifetimeEndsAt()?->lte($limit))->sortBy(fn (Equipment $e) => $e->lifetimeEndsAt())->values(),
            'canPerform' => WartungService::canPerform($user),
            'canManage' => WartungService::canManage($user),
        ]);
    }
}

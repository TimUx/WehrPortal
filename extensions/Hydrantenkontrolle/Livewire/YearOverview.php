<?php

namespace Extensions\Hydrantenkontrolle\Livewire;

use Extensions\Hydrantenkontrolle\Models\InspectionYear;
use Extensions\Hydrantenkontrolle\Services\InspectionService;
use Extensions\Hydrantenkontrolle\Support\Checklist;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class YearOverview extends Component
{
    use WithPagination;

    #[Locked]
    public ?int $yearId = null;

    #[Locked]
    public ?int $inspectionId = null;

    #[Locked]
    public int $version = 0;

    public string $newYear = '';

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    public array $form = [];

    public function mount(?InspectionYear $year = null): void
    {
        Gate::authorize('manage-hydrantenkontrolle');
        $this->newYear = (string) now()->year;
        if ($year?->exists) {
            $this->yearId = InspectionYear::findOrFail($year->id)->id;
            $this->newYear = (string) $year->year;
        }
    }

    public function openYear(InspectionService $service)
    {
        $this->validate(['newYear' => ['required', 'integer', 'between:2000,2100']], [
            'newYear.*' => 'Bitte ein Jahr zwischen 2000 und 2100 angeben.',
        ]);
        $year = $service->openYear(auth()->user(), (int) $this->newYear);

        return $this->redirectRoute('hydrantenkontrolle.year', $year);
    }

    public function edit(int $id): void
    {
        Gate::authorize('manage-hydrantenkontrolle');
        $inspection = $this->year()->inspections()->findOrFail($id);
        $this->inspectionId = $inspection->id;
        $this->version = $inspection->version;
        $this->form = [
            'checked_at' => $inspection->checked_at?->format('Y-m-d') ?? '',
            'is_ok' => $inspection->is_ok,
            'defects' => $inspection->defects ?? [],
            'notes' => $inspection->notes ?? '',
        ];
        $this->resetValidation();
    }

    public function closeEditor(): void
    {
        $this->inspectionId = null;
        $this->form = [];
        $this->resetValidation();
    }

    public function save(InspectionService $service): void
    {
        $inspection = $this->year()->inspections()->findOrFail($this->inspectionId);
        $service->saveInspection(auth()->user(), $inspection, $this->form, $this->version);
        $this->closeEditor();
        session()->flash('flash', 'Kontrolle gespeichert.');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    private function year(): InspectionYear
    {
        Gate::authorize('manage-hydrantenkontrolle');

        return InspectionYear::findOrFail($this->yearId);
    }

    public function render()
    {
        Gate::authorize('manage-hydrantenkontrolle');
        $year = $this->yearId ? $this->year() : null;
        $query = $year?->inspections();
        $counts = $year ? [
            'Gesamt' => $year->inspections()->count(),
            'Ungeprüft' => $year->inspections()->whereNull('checked_at')->count(),
            'In Ordnung' => $year->inspections()->whereNotNull('checked_at')->where('is_ok', true)->count(),
            'Mit Mängeln' => $year->inspections()->whereNotNull('checked_at')->where('is_ok', false)->count(),
        ] : [];
        if ($query && $this->search !== '') {
            $term = '%'.$this->search.'%';
            $query->where(fn ($q) => $q->where('snapshot->city', 'ilike', $term)->orWhere('snapshot->number', 'ilike', $term)->orWhere('snapshot->street', 'ilike', $term));
        }
        if ($query) {
            match ($this->status) {
                'unchecked' => $query->whereNull('checked_at'),
                'ok' => $query->whereNotNull('checked_at')->where('is_ok', true),
                'defects' => $query->whereNotNull('checked_at')->where('is_ok', false),
                default => null,
            };
        }

        return view('hydrantenkontrolle::livewire.year-overview', [
            'year' => $year,
            'years' => InspectionYear::whereHas('inspections')->with('location')->orderByDesc('year')->get(),
            'counts' => $counts,
            'inspections' => $query?->orderBy('snapshot->number')->paginate(25),
            'editing' => $this->inspectionId && $year ? $year->inspections()->findOrFail($this->inspectionId) : null,
            'defects' => Checklist::DEFECTS,
        ]);
    }
}

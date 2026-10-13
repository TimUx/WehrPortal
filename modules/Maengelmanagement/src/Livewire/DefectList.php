<?php

namespace Modules\Maengelmanagement\src\Livewire;

use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Maengelmanagement\src\Enums\DefectStatus;
use Modules\Maengelmanagement\src\Models\Defect;

/** Every member sees every defect and its status */
class DefectList extends Component
{
    use WithPagination;

    /** active | all | one of the DefectStatus values */
    #[Url(as: 'status')]
    public string $filter = 'active';

    #[Url(as: 'q')]
    public string $search = '';

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $defects = Defect::with(['device', 'vehicle', 'reporter', 'assignee'])
            ->withCount('photos')
            ->when($this->filter === 'active', fn ($q) => $q->active())
            ->when(DefectStatus::tryFrom($this->filter), fn ($q, $status) => $q->where('status', $status->value))
            ->when(trim($this->search) !== '', function ($q) {
                $term = '%'.trim($this->search).'%';
                $q->where(fn ($q) => $q->where('title', 'ilike', $term)
                    ->orWhere('location', 'ilike', $term)
                    ->orWhereHas('device', fn ($d) => $d->where('name', 'ilike', $term)->orWhere('code', 'ilike', $term))
                    ->orWhereHas('vehicle', fn ($v) => $v->where('name', 'ilike', $term)));
            })
            // Critical first, then newest
            ->orderByRaw("case urgency when 'critical' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")
            ->latest()
            ->paginate(15);

        return view('maengelmanagement::livewire.defect-list', [
            'defects' => $defects,
            'openCount' => Defect::active()->count(),
        ]);
    }
}

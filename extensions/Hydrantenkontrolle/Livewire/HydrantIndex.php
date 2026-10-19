<?php

namespace Extensions\Hydrantenkontrolle\Livewire;

use Extensions\Hydrantenkontrolle\Models\Hydrant;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class HydrantIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = 'active';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        Gate::authorize('manage-hydrantenkontrolle');
        $query = Hydrant::query();
        if ($this->status !== 'all') {
            $query->where('active', $this->status !== 'archived');
        }
        if ($this->search !== '') {
            $term = '%'.$this->search.'%';
            $query->where(fn ($q) => $q->where('city', 'ilike', $term)->orWhere('number', 'ilike', $term)->orWhere('street', 'ilike', $term)->orWhere('location_description', 'ilike', $term));
        }

        return view('hydrantenkontrolle::livewire.hydrant-index', [
            'hydrants' => $query->orderBy('number')->paginate(25),
        ]);
    }
}

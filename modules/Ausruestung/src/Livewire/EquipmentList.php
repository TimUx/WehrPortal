<?php

namespace Modules\Ausruestung\src\Livewire;

use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Ausruestung\src\Enums\EquipmentStatus;
use Modules\Ausruestung\src\Models\Category;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Ausruestung\src\Services\EquipmentService;

/** Every member sees the equipment with status, place and inspection date */
class EquipmentList extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'kategorie')]
    public string $category = '';

    #[Url(as: 'status')]
    public string $status = '';

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $relations = ['category', 'location', 'compartment.location'];
        if (config('locations.enabled', false)) {
            $relations[] = 'standort';
        }

        $equipment = EquipmentService::equipmentFor(auth()->user())->with($relations)
            ->inService()
            ->when(trim($this->search) !== '', function ($q) {
                $term = '%'.trim($this->search).'%';
                $q->where(fn ($q) => $q->where('name', 'ilike', $term)->orWhere('code', 'ilike', $term)
                    ->orWhere('serial_number', 'ilike', $term)->orWhere('manufacturer', 'ilike', $term)->orWhere('model', 'ilike', $term));
            })
            ->when($this->category !== '', fn ($q) => $q->where('category_id', $this->category))
            ->when(EquipmentStatus::tryFrom($this->status), fn ($q, $status) => $q->where('status', $status->value))
            ->orderBy('name')
            ->paginate(20);

        return view('ausruestung::livewire.equipment-list', [
            'equipment' => $equipment,
            'categories' => Category::orderBy('sort_order')->pluck('name', 'id'),
            'statuses' => collect(EquipmentStatus::cases())->reject(fn ($s) => $s === EquipmentStatus::Retired),
        ]);
    }
}

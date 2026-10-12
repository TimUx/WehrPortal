<?php

namespace Modules\Objektplaene\src\Livewire;

use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Objektplaene\src\Models\PlanObject;
use Modules\Objektplaene\src\Services\ObjectPlanService;

/** All objects: search, filter by type and Gefährdungsklasse, map, "Mein Standort", offline */
class Library extends Component
{
    #[Url(as: 'suche')]
    public string $search = '';

    #[Url(as: 'typ')]
    public string $type = '';

    #[Url(as: 'gefaehrdung')]
    public string $hazard = '';

    /** Only objects whose review is due (link in the reminder mail) */
    #[Url(as: 'pruefung')]
    public bool $due = false;

    public bool $showMap = false;

    /** @var array{lat: float, lng: float}|null "Mein Standort" */
    public ?array $point = null;

    public function nearPoint(float $lat, float $lng): void
    {
        $this->point = ['lat' => $lat, 'lng' => $lng];
    }

    public function updated(): void
    {
        $this->dispatch('water-markers', markers: ObjectPlanService::markers($this->objects()));
    }

    private function objects()
    {
        $term = trim($this->search);

        return PlanObject::active()->withCount('documents')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'ilike', '%'.$term.'%')->orWhere('address', 'ilike', '%'.$term.'%')))
            ->when(array_key_exists($this->type, PlanObject::TYPES), fn ($q) => $q->where('object_type', $this->type))
            ->when(array_key_exists($this->hazard, PlanObject::HAZARD_CLASSES), fn ($q) => $q->where('hazard_class', $this->hazard))
            ->when($this->due, fn ($q) => $q->whereNotNull('next_review_on')->where('next_review_on', '<=', ObjectPlanService::today()->toDateString()))
            ->orderBy('name')->get();
    }

    public function render(ObjectPlanService $plans)
    {
        $objects = $this->objects();
        $nearby = $this->point
            ? $objects->filter(fn (PlanObject $o) => $o->hasPosition())
                ->map(fn (PlanObject $o) => ['object' => $o, 'meters' => (int) round(ObjectPlanService::distance($this->point['lat'], $this->point['lng'], $o->latitude, $o->longitude))])
                ->sortBy('meters')->take(10)->values()
            : collect();

        return view('objektplaene::livewire.library', [
            'objects' => $objects,
            'nearby' => $nearby,
            'running' => $plans->running(),
            'markers' => ObjectPlanService::markers($objects),
            'offlineUrls' => $plans->offlineUrls(),
            'manage' => ObjectPlanService::canManage(auth()->user()),
        ]);
    }
}

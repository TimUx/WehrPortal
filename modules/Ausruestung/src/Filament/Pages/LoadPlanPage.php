<?php

namespace Modules\Ausruestung\src\Filament\Pages;

use Filament\Pages\Page;
use Livewire\Attributes\Url;
use Modules\Ausruestung\src\Models\Location;
use Modules\Ausruestung\src\Services\LoadPlanService;

/** Beladeplan: Soll-/Ist-Vergleich per vehicle at a glance */
class LoadPlanPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'Beladeplan';

    protected static ?string $title = 'Beladeplan';

    protected static ?string $slug = 'beladeplan';

    protected static ?int $navigationSort = 15;

    protected string $view = 'ausruestung::filament.pages.load-plan';

    #[Url]
    public ?int $location = null;

    public function mount(): void
    {
        $this->location ??= Location::whereHas('compartments.loadPlanItems')->orderByRaw("kind = 'vehicle' desc")->orderBy('sort_order')->value('id');
    }

    protected function getViewData(): array
    {
        $service = app(LoadPlanService::class);
        $current = $this->location ? Location::find($this->location) : null;

        return [
            'locations' => Location::orderByRaw("kind = 'vehicle' desc")->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (Location $l) => ['id' => $l->id, 'name' => $l->name, 'missing' => $service->missingCount($l)]),
            'current' => $current,
            'comparison' => $current ? $service->compare($current) : collect(),
            'unassigned' => $current ? $service->unassigned($current) : collect(),
        ];
    }
}

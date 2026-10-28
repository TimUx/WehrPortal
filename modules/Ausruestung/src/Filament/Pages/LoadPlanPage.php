<?php

namespace Modules\Ausruestung\src\Filament\Pages;

use App\Models\User;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Modules\Ausruestung\src\Filament\Concerns\AusruestungArea;
use Modules\Ausruestung\src\Models\Location;
use Modules\Ausruestung\src\Services\EquipmentService;
use Modules\Ausruestung\src\Services\LoadPlanService;

/** Beladeplan: Soll-/Ist-Vergleich per vehicle at a glance */
class LoadPlanPage extends Page
{
    use AusruestungArea;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'Beladeplan';

    protected static ?string $title = 'Beladeplan';

    protected static ?string $slug = 'ausruestung/beladeplan';

    protected static ?int $navigationSort = 15;

    protected string $view = 'ausruestung::filament.pages.load-plan';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && static::canAccessArea() && EquipmentService::canManage($user);
    }

    #[Url]
    public ?int $location = null;

    public function mount(): void
    {
        $this->location ??= $this->locationsQuery()->whereHas('compartments.loadPlanItems')
            ->orderByRaw("kind = 'vehicle' desc")->orderBy('sort_order')->value('id');
    }

    protected function getViewData(): array
    {
        $service = app(LoadPlanService::class);
        $current = $this->location ? $this->locationsQuery()->find($this->location) : null;

        return [
            'locations' => $this->locationsQuery()->orderByRaw("kind = 'vehicle' desc")->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (Location $l) => ['id' => $l->id, 'name' => $l->name, 'missing' => $service->missingCount($l)]),
            'current' => $current,
            'comparison' => $current ? $service->compare($current) : collect(),
            'unassigned' => $current ? $service->unassigned($current) : collect(),
        ];
    }

    /** @return Builder<Location> */
    private function locationsQuery(): Builder
    {
        $query = Location::query();
        $user = auth()->user();

        if (config('locations.enabled') && $user instanceof User) {
            $query->where(function (Builder $query) use ($user): void {
                $query->whereIn('ext_location_id', EquipmentService::manageableLocationIds($user));
                if ($user->is_admin) {
                    $query->orWhereNull('ext_location_id');
                }
            });
        }

        return $query;
    }
}

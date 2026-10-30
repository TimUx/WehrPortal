<?php

namespace Modules\Atemschutz\src\Filament\Resources\WearerResource\Pages;

use App\Filament\ModuleArea;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Pagination\Paginator;
use Modules\Atemschutz\src\Filament\Resources\WearerResource;
use Modules\Atemschutz\src\Models\Wearer;
use Modules\Atemschutz\src\Services\MonitorService;
use Modules\Atemschutz\src\Services\ReadinessService;
use UnitEnum;

class ListWearers extends ListRecords implements ModuleArea
{
    protected static string $resource = WearerResource::class;

    /** @var array<int, array<string, mixed>>|null readiness of the listed Geräteträger for this request */
    protected ?array $states = null;

    public static function allows(User $user): bool
    {
        return MonitorService::canCare($user);
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return WearerResource::getNavigationGroup();
    }

    public function getSubheading(): ?string
    {
        return 'Einsatzbereit ist, wer den AGT-Lehrgang hat, eine gültige G26.3 und in den letzten '.ReadinessService::VALID_MONTHS
            .' Monaten Unterweisung und Belastungsübung (FwDV 7). CSA-Träger brauchen zusätzlich die CSA-Ausbildung und eine CSA-Übung.';
    }

    /** @return array<string, mixed>|null */
    public function state(Wearer $wearer): ?array
    {
        if ($this->states === null) {
            $records = $this->getTableRecords();
            $items = $records instanceof Paginator ? collect($records->items()) : collect($records);
            $this->states = WearerResource::states($items)->all();
        }

        return $this->states[$wearer->user_id] ?? null;
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Kamerad in den Pool aufnehmen')];
    }
}

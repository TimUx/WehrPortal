<?php

namespace Modules\Kleiderkammer\src\Filament\Resources\StockResource\Pages;

use App\Filament\ModuleArea;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Modules\Kleiderkammer\src\Filament\Resources\StockResource;
use Modules\Kleiderkammer\src\Services\StockAccess;
use UnitEnum;

class ListStock extends ListRecords implements ModuleArea
{
    protected static string $resource = StockResource::class;

    public static function allows(User $user): bool
    {
        $access = app(StockAccess::class);

        return $access->enabled() && $access->canEnter($user);
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return StockResource::getNavigationGroup();
    }

    public function getSubheading(): ?string
    {
        return app(StockAccess::class)->enabled()
            ? 'Standortfreigaben gelten nur für Mengenbestand, nicht für die persönliche Verwaltung. Keine vollständige Standortisolation.'
            : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Bestandsposition anlegen'),
        ];
    }
}

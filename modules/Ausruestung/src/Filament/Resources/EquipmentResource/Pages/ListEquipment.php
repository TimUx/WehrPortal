<?php

namespace Modules\Ausruestung\src\Filament\Resources\EquipmentResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Modules\Ausruestung\src\Filament\Resources\EquipmentResource;
use Modules\Ausruestung\src\Services\InventoryExport;

class ListEquipment extends ListRecords
{
    protected static string $resource = EquipmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('export')
                ->label('Inventur-Export (CSV)')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => app(InventoryExport::class)->download(
                    $this->getFilteredTableQuery(),
                    'ausruestung-inventur-'.now()->format('Y-m-d').'.csv',
                )),
            Actions\CreateAction::make()->label('Gerät anlegen'),
        ];
    }

    public function getSubheading(): ?string
    {
        return 'Der Export enthält alle Geräte, die die aktuellen Filter und die Suche treffen.';
    }
}

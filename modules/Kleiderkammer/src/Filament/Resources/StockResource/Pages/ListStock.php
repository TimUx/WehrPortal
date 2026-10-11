<?php

namespace Modules\Kleiderkammer\src\Filament\Resources\StockResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Modules\Kleiderkammer\src\Filament\Resources\StockResource;

class ListStock extends ListRecords
{
    protected static string $resource = StockResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Bestandsposition anlegen'),
        ];
    }
}

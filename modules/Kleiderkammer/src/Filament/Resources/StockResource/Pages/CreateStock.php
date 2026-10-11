<?php

namespace Modules\Kleiderkammer\src\Filament\Resources\StockResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Kleiderkammer\src\Filament\Resources\StockResource;

class CreateStock extends CreateRecord
{
    protected static string $resource = StockResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

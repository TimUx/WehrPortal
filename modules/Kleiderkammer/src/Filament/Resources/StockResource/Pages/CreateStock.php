<?php

namespace Modules\Kleiderkammer\src\Filament\Resources\StockResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Kleiderkammer\src\Filament\Resources\StockResource;
use Modules\Kleiderkammer\src\Services\StockAccess;

class CreateStock extends CreateRecord
{
    protected static string $resource = StockResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(StockAccess::class)->createStock(auth()->user(), $data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

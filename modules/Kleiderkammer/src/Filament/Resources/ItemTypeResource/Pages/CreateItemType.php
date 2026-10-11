<?php

namespace Modules\Kleiderkammer\src\Filament\Resources\ItemTypeResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Kleiderkammer\src\Filament\Resources\ItemTypeResource;

class CreateItemType extends CreateRecord
{
    protected static string $resource = ItemTypeResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

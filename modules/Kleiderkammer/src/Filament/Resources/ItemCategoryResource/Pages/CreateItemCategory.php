<?php

namespace Modules\Kleiderkammer\src\Filament\Resources\ItemCategoryResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Kleiderkammer\src\Filament\Resources\ItemCategoryResource;

class CreateItemCategory extends CreateRecord
{
    protected static string $resource = ItemCategoryResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

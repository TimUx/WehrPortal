<?php

namespace Modules\Kleiderkammer\src\Filament\Resources\ItemCategoryResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Modules\Kleiderkammer\src\Filament\Resources\ItemCategoryResource;

class ListItemCategories extends ListRecords
{
    protected static string $resource = ItemCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Kategorie anlegen'),
        ];
    }
}

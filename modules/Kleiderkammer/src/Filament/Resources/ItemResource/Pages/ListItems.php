<?php

namespace Modules\Kleiderkammer\src\Filament\Resources\ItemResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Modules\Kleiderkammer\src\Filament\Resources\ItemResource;

class ListItems extends ListRecords
{
    protected static string $resource = ItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Teil anlegen'),
        ];
    }
}

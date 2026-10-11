<?php

namespace Modules\Ausruestung\src\Filament\Resources\LocationResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Ausruestung\src\Filament\Resources\LocationResource;

class ListLocations extends ListRecords
{
    protected static string $resource = LocationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Standort anlegen')];
    }
}

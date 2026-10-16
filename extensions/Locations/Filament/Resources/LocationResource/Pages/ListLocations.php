<?php

namespace Extensions\Locations\Filament\Resources\LocationResource\Pages;

use Extensions\Locations\Filament\Resources\LocationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLocations extends ListRecords
{
    protected static string $resource = LocationResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Standort anlegen')];
    }
}

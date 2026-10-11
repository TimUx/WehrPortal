<?php

namespace Modules\Fahrzeugbuchung\src\Filament\Resources\VehicleResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Modules\Fahrzeugbuchung\src\Filament\Resources\VehicleResource;

class ListVehicles extends ListRecords
{
    protected static string $resource = VehicleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Fahrzeug anlegen'),
        ];
    }
}

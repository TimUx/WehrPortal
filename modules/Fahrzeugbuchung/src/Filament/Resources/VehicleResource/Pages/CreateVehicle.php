<?php

namespace Modules\Fahrzeugbuchung\src\Filament\Resources\VehicleResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Fahrzeugbuchung\src\Filament\Resources\VehicleResource;

class CreateVehicle extends CreateRecord
{
    protected static string $resource = VehicleResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

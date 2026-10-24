<?php

namespace Extensions\Locations\Filament\Resources\LocationResource\Pages;

use Extensions\Locations\Filament\Resources\LocationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLocation extends CreateRecord
{
    protected static string $resource = LocationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return LocationResource::prepareBranding($data);
    }
}

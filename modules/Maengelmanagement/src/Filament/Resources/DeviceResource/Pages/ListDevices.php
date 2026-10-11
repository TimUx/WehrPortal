<?php

namespace Modules\Maengelmanagement\src\Filament\Resources\DeviceResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Maengelmanagement\src\Filament\Resources\DeviceResource;

class ListDevices extends ListRecords
{
    protected static string $resource = DeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Gerät anlegen')];
    }
}

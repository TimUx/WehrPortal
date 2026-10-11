<?php

namespace Modules\Maengelmanagement\src\Filament\Resources\DeviceResource\Pages;

use Filament\Resources\Pages\EditRecord;
use Modules\Maengelmanagement\src\Filament\Resources\DeviceResource;

class EditDevice extends EditRecord
{
    protected static string $resource = DeviceResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

<?php

namespace Modules\Maengelmanagement\src\Filament\Resources\DeviceResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Maengelmanagement\src\Filament\Resources\DeviceResource;

class CreateDevice extends CreateRecord
{
    protected static string $resource = DeviceResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

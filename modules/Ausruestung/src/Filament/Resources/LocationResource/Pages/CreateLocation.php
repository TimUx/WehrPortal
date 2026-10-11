<?php

namespace Modules\Ausruestung\src\Filament\Resources\LocationResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Ausruestung\src\Filament\Resources\LocationResource;

class CreateLocation extends CreateRecord
{
    protected static string $resource = LocationResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}

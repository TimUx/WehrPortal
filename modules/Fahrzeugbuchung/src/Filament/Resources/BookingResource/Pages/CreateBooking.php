<?php

namespace Modules\Fahrzeugbuchung\src\Filament\Resources\BookingResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Fahrzeugbuchung\src\Filament\Resources\BookingResource;

class CreateBooking extends CreateRecord
{
    protected static string $resource = BookingResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

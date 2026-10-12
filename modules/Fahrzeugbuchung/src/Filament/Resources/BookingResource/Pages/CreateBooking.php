<?php

namespace Modules\Fahrzeugbuchung\src\Filament\Resources\BookingResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Fahrzeugbuchung\src\Filament\Resources\BookingResource;
use Modules\Fahrzeugbuchung\src\Services\BookingService;

class CreateBooking extends CreateRecord
{
    protected static string $resource = BookingResource::class;

    /** Booked for someone else: they hear about it */
    protected function afterCreate(): void
    {
        app(BookingService::class)->tellOwner($this->record, 'created');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

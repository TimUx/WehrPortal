<?php

namespace Modules\Fahrzeugbuchung\src\Filament\Resources\BookingResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Modules\Fahrzeugbuchung\src\Filament\Resources\BookingResource;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Buchung anlegen'),
        ];
    }
}

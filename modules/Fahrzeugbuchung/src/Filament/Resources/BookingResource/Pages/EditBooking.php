<?php

namespace Modules\Fahrzeugbuchung\src\Filament\Resources\BookingResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Modules\Fahrzeugbuchung\src\Filament\Resources\BookingResource;
use Modules\Fahrzeugbuchung\src\Services\BookingService;
use Modules\Fahrzeugbuchung\src\Services\DecisionService;

class EditBooking extends EditRecord
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Löschen')
                // Cancel first so pending requests are withdrawn and freed slots go back to earlier losers
                ->before(fn () => app(BookingService::class)->cancel($this->record)),
        ];
    }

    protected function afterSave(): void
    {
        app(DecisionService::class)->releaseSlotsFreedBy($this->record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

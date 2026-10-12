<?php

namespace Modules\Fahrzeugbuchung\src\Filament\Resources\BookingResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Modules\Fahrzeugbuchung\src\Filament\Resources\BookingResource;
use Modules\Fahrzeugbuchung\src\Notifications\BookingSummary;
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

    private ?string $before = null;

    protected function beforeSave(): void
    {
        $this->before = BookingSummary::line($this->record);
    }

    protected function afterSave(): void
    {
        app(DecisionService::class)->releaseSlotsFreedBy($this->record);
        if ($this->record->wasChanged(['vehicle_id', 'user_id', 'starts_at', 'ends_at', 'purpose', 'destination'])) {
            app(BookingService::class)->tellOwner($this->record, 'updated', $this->before);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

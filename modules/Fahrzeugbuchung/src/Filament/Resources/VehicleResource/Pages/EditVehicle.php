<?php

namespace Modules\Fahrzeugbuchung\src\Filament\Resources\VehicleResource\Pages;

use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Fahrzeugbuchung\src\Filament\Resources\VehicleResource;

class EditVehicle extends EditRecord
{
    protected static string $resource = VehicleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Löschen')
                ->before(function (Actions\DeleteAction $action) {
                    $hasTrips = Schema::hasTable('ft_trips') && DB::table('ft_trips')->where('vehicle_id', $this->record->getKey())->exists();
                    $hasFuelings = Schema::hasTable('ft_fuelings') && DB::table('ft_fuelings')->where('vehicle_id', $this->record->getKey())->exists();
                    if ($this->record->bookings()->exists() || $hasTrips || $hasFuelings) {
                        Notification::make()
                            ->title('Fahrzeug kann nicht gelöscht werden')
                            ->body('Es existieren Buchungen oder Fahrtenbucheinträge für dieses Fahrzeug. Deaktiviere es stattdessen.')
                            ->danger()
                            ->send();

                        $action->cancel();
                    }
                }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

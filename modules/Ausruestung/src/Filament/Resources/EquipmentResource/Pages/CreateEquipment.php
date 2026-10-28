<?php

namespace Modules\Ausruestung\src\Filament\Resources\EquipmentResource\Pages;

use Extensions\Locations\Services\LocationContext;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;
use Modules\Ausruestung\src\Filament\Resources\EquipmentResource;
use Modules\Ausruestung\src\Services\EquipmentService;

class CreateEquipment extends CreateRecord
{
    protected static string $resource = EquipmentResource::class;

    /** The code from a scan of an unknown label is prefilled */
    protected function fillForm(): void
    {
        $data = ['code' => request()->query('code')];
        if (config('locations.enabled', false)) {
            $user = auth()->user();
            $scope = EquipmentService::equipmentManagementScope($user);
            $current = app(LocationContext::class)->forUser($user)['current'];
            $data['ext_location_id'] = $scope === null || ($current !== null && in_array($current, $scope, true))
                ? $current
                : ($scope[0] ?? null);
        }

        $this->form->fill($data);
    }

    protected function afterCreate(): void
    {
        app(EquipmentService::class)->log($this->record, auth()->user(), 'note', 'Erfasst');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (config('locations.enabled', false)) {
            if (! filled($data['ext_location_id'] ?? null)) {
                throw ValidationException::withMessages(['ext_location_id' => 'Bitte einen Standort auswählen.']);
            }
            if (! EquipmentService::canManageLocation(auth()->user(), (int) $data['ext_location_id'])) {
                throw ValidationException::withMessages(['ext_location_id' => 'Du darfst Geräte nicht an diesem Standort verwalten.']);
            }
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}

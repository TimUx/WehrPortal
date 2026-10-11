<?php

namespace Modules\Ausruestung\src\Filament\Resources\EquipmentResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Ausruestung\src\Filament\Resources\EquipmentResource;
use Modules\Ausruestung\src\Services\EquipmentService;

class CreateEquipment extends CreateRecord
{
    protected static string $resource = EquipmentResource::class;

    /** The code from a scan of an unknown label is prefilled */
    protected function fillForm(): void
    {
        $this->form->fill(['code' => request()->query('code')]);
    }

    protected function afterCreate(): void
    {
        app(EquipmentService::class)->log($this->record, auth()->user(), 'note', 'Erfasst');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}

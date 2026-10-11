<?php

namespace Modules\Ausruestung\src\Filament\Resources\EquipmentResource\Pages;

use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Ausruestung\src\Filament\Resources\EquipmentResource;
use Modules\Ausruestung\src\Filament\Support\EquipmentActions;
use Modules\Ausruestung\src\Services\EquipmentService;

class EditEquipment extends EditRecord
{
    protected static string $resource = EquipmentResource::class;

    protected function getHeaderActions(): array
    {
        $item = fn ($record, $livewire) => $livewire->getRecord();

        return [
            EquipmentActions::inspect($item),
            EquipmentActions::lend($item),
            EquipmentActions::reportDamage($item),
            ActionGroup::make([
                EquipmentActions::logUse($item),
                EquipmentActions::qr($item),
                EquipmentActions::retire($item),
                EquipmentActions::reactivate($item),
            ]),
        ];
    }

    /** A change of place goes into the history */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $service = app(EquipmentService::class);
        $service->move($record, auth()->user(), $data['location_id'] ?? null, $data['compartment_id'] ?? null);
        unset($data['location_id'], $data['compartment_id']);

        $record->update($data);

        return $record;
    }
}

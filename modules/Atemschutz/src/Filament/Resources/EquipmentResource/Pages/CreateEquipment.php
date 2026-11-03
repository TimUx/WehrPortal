<?php

namespace Modules\Atemschutz\src\Filament\Resources\EquipmentResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Atemschutz\src\Filament\Resources\EquipmentResource;
use Modules\Atemschutz\src\Services\EquipmentService;

class CreateEquipment extends CreateRecord
{
    protected static string $resource = EquipmentResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $components = $data['component_ids'] ?? [];
        unset($data['component_ids']);

        return DB::transaction(function () use ($data, $components) {
            $service = app(EquipmentService::class);
            $equipment = $service->createEquipment($data, auth()->user());
            if ($components !== []) {
                $service->syncComponents($equipment, $components, auth()->user());
            }

            return $equipment;
        });
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}

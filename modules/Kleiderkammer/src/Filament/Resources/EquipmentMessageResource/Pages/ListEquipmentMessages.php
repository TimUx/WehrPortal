<?php

namespace Modules\Kleiderkammer\src\Filament\Resources\EquipmentMessageResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Kleiderkammer\src\Filament\Resources\EquipmentMessageResource;

class ListEquipmentMessages extends ListRecords
{
    protected static string $resource = EquipmentMessageResource::class;

    public function getSubheading(): ?string
    {
        return 'Nachrichten von Mitgliedern zu ihrer ausgegebenen Ausrüstung.';
    }
}

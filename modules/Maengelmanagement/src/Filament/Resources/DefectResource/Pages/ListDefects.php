<?php

namespace Modules\Maengelmanagement\src\Filament\Resources\DefectResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Maengelmanagement\src\Filament\Resources\DefectResource;

class ListDefects extends ListRecords
{
    protected static string $resource = DefectResource::class;

    public function getSubheading(): ?string
    {
        return 'Mängel werden von Mitgliedern in der App gemeldet.';
    }
}

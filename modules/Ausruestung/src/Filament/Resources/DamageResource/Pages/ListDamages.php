<?php

namespace Modules\Ausruestung\src\Filament\Resources\DamageResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Ausruestung\src\Filament\Resources\DamageResource;

class ListDamages extends ListRecords
{
    protected static string $resource = DamageResource::class;

    public function getSubheading(): ?string
    {
        return 'Ein gemeldeter Schaden sperrt das Gerät („Defekt“). Ist die letzte Reparatur erledigt, ist es wieder einsatzbereit.';
    }
}

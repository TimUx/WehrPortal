<?php

namespace Modules\Ausruestung\src\Filament\Resources\EquipmentResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Modules\Ausruestung\src\Filament\Resources\DamageResource;
use Modules\Ausruestung\src\Filament\Support\EquipmentActions;

class DamagesRelationManager extends RelationManager
{
    protected static string $relationship = 'damages';

    protected static ?string $title = 'Schäden & Reparaturen';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return DamageResource::table($table)
            ->headerActions([
                EquipmentActions::reportDamage(fn ($record, $livewire) => $livewire->getOwnerRecord()),
            ]);
    }
}

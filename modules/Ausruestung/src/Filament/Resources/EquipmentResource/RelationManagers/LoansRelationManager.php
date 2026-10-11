<?php

namespace Modules\Ausruestung\src\Filament\Resources\EquipmentResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Modules\Ausruestung\src\Filament\Resources\LoanResource;
use Modules\Ausruestung\src\Filament\Support\EquipmentActions;

class LoansRelationManager extends RelationManager
{
    protected static string $relationship = 'loans';

    protected static ?string $title = 'Ausleihe';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return LoanResource::table($table)
            ->headerActions([
                EquipmentActions::lend(fn ($record, $livewire) => $livewire->getOwnerRecord()),
                LoanResource::reserveAction(fn ($livewire) => $livewire->getOwnerRecord()),
            ]);
    }
}

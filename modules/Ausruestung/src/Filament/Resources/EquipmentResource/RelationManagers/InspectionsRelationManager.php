<?php

namespace Modules\Ausruestung\src\Filament\Resources\EquipmentResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Ausruestung\src\Filament\Support\EquipmentActions;

class InspectionsRelationManager extends RelationManager
{
    protected static string $relationship = 'inspections';

    protected static ?string $title = 'Prüfungen';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('inspected_at')->label('Geprüft am')->date('d.m.Y')->sortable(),
                Tables\Columns\IconColumn::make('passed')->label('Bestanden')->boolean(),
                Tables\Columns\TextColumn::make('next_due_at')->label('Nächste Prüfung')->date('d.m.Y')->placeholder('–'),
                Tables\Columns\TextColumn::make('user.name')->label('Prüfer')->placeholder('–'),
                Tables\Columns\TextColumn::make('notes')->label('Bemerkung')->wrap()->placeholder('–'),
            ])
            ->defaultSort('inspected_at', 'desc')
            ->headerActions([
                EquipmentActions::inspect(fn ($record, $livewire) => $livewire->getOwnerRecord()),
            ]);
    }
}

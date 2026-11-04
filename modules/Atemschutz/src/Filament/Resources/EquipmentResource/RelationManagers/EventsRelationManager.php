<?php

namespace Modules\Atemschutz\src\Filament\Resources\EquipmentResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'Verlauf';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('occurred_at')->label('Zeitpunkt')->dateTime('d.m.Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('kind')->label('Ereignis'),
                Tables\Columns\TextColumn::make('from_location')->label('Von')->placeholder('–'),
                Tables\Columns\TextColumn::make('to_location')->label('Nach')->placeholder('–'),
                Tables\Columns\TextColumn::make('from_status')->label('Status vorher')->placeholder('–'),
                Tables\Columns\TextColumn::make('to_status')->label('Status nachher')->placeholder('–'),
                Tables\Columns\TextColumn::make('notes')->label('Vermerk')->wrap(),
                Tables\Columns\TextColumn::make('user.name')->label('Erfasst von')->placeholder('–'),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([])
            ->defaultSort('occurred_at', 'desc');
    }
}

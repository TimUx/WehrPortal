<?php

namespace Modules\Atemschutz\src\Filament\Resources\WearerResource\RelationManagers;

use Filament\Actions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Atemschutz\src\Models\Training;

class TrainingsRelationManager extends RelationManager
{
    protected static string $relationship = 'trainings';

    protected static ?string $title = 'Nachweise';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('done_on')->label('Datum')->date('d.m.Y'),
                Tables\Columns\TextColumn::make('kind')->label('Art')->state(fn (Training $record) => $record->kindLabel().($record->csa ? ' (CSA)' : '')),
                Tables\Columns\TextColumn::make('minutes')->label('Tragezeit')->suffix(' min')->placeholder('–'),
                Tables\Columns\TextColumn::make('notes')->label('Notiz')->placeholder('–'),
            ])
            ->defaultSort('done_on', 'desc')
            ->actions([Actions\DeleteAction::make()->requiresConfirmation()])
            ->paginated([10, 25]);
    }
}

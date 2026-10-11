<?php

namespace Modules\Maengelmanagement\src\Filament\Resources\DefectResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Maengelmanagement\src\Models\DefectEvent;

class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'Verlauf';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Wann')->dateTime('d.m.Y H:i')->timezone('Europe/Berlin'),
                Tables\Columns\TextColumn::make('user.name')->label('Wer')->placeholder('–'),
                Tables\Columns\TextColumn::make('summary')->label('Was')->state(fn (DefectEvent $record) => $record->summary()),
                Tables\Columns\TextColumn::make('body')->label('Notiz')->wrap()->placeholder('–'),
            ])
            ->defaultSort('id', 'desc')
            ->paginated(false);
    }
}

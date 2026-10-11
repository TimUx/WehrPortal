<?php

namespace Modules\Ausruestung\src\Filament\Resources\EquipmentResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Ausruestung\src\Filament\Support\EquipmentActions;
use Modules\Ausruestung\src\Models\EquipmentEvent;

/** Einsatzhistorie and everything else that happened to the item */
class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'Verlauf';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('occurred_at')->label('Wann')->dateTime('d.m.Y H:i')->timezone('Europe/Berlin')->sortable(),
                Tables\Columns\TextColumn::make('type')->label('Art')->badge()->formatStateUsing(fn (string $state) => EquipmentEvent::TYPES[$state] ?? $state)
                    ->color(fn (string $state) => in_array($state, ['operation', 'exercise'], true) ? 'info' : 'gray'),
                Tables\Columns\TextColumn::make('body')->label('Was')->wrap()->placeholder('–'),
                Tables\Columns\TextColumn::make('user.name')->label('Wer')->placeholder('–'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->label('Art')->options(EquipmentEvent::TYPES)->multiple(),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->headerActions([
                EquipmentActions::logUse(fn ($record, $livewire) => $livewire->getOwnerRecord()),
            ]);
    }
}

<?php

namespace Modules\Ausruestung\src\Filament\Resources\EquipmentResource\RelationManagers;

use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Ausruestung\src\Models\Recall;
use Modules\Ausruestung\src\Services\RecallService;

class RecallsRelationManager extends RelationManager
{
    protected static string $relationship = 'recalls';

    protected static ?string $title = 'Rückrufe';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Rückruf'),
                Tables\Columns\TextColumn::make('pivot.created_at')->label('Gesperrt seit')->dateTime('d.m.Y')->timezone('Europe/Berlin'),
                Tables\Columns\IconColumn::make('pivot.resolved_at')->label('Erledigt')->boolean()
                    ->getStateUsing(fn (Recall $record) => $record->pivot->resolved_at !== null),
                Tables\Columns\TextColumn::make('pivot.resolution')->label('Erledigung')->placeholder('–')->wrap(),
            ])
            ->recordActions([
                Actions\Action::make('resolve')
                    ->label('Erledigt')
                    ->icon('heroicon-o-check')
                    ->visible(fn (Recall $record) => $record->pivot->resolved_at === null)
                    ->schema([Forms\Components\Textarea::make('resolution')->label('Was wurde gemacht?')->rows(2)->placeholder('z. B. geprüft, nicht betroffen / Bauteil getauscht')])
                    ->action(function (Recall $record, array $data) {
                        app(RecallService::class)->resolve($record, $this->getOwnerRecord(), auth()->user(), $data['resolution'] ?? null);
                        Notification::make()->title('Rückruf für dieses Gerät erledigt')->success()->send();
                    }),
            ]);
    }
}

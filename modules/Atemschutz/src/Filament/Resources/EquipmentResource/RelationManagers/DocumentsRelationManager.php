<?php

namespace Modules\Atemschutz\src\Filament\Resources\EquipmentResource\RelationManagers;

use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Atemschutz\src\Models\Equipment;
use Modules\Atemschutz\src\Models\EquipmentDocument;
use Modules\Atemschutz\src\Services\EquipmentService;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Dokumente und Fotos';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Titel')->searchable(),
                Tables\Columns\TextColumn::make('kind')->label('Art')->formatStateUsing(fn (string $state) => EquipmentDocument::KINDS[$state] ?? $state),
                Tables\Columns\TextColumn::make('filename')->label('Datei')->searchable(),
                Tables\Columns\TextColumn::make('size')->label('Größe')->formatStateUsing(fn (int $state) => number_format($state / 1024, 0, ',', '.').' KB'),
                Tables\Columns\TextColumn::make('uploader.name')->label('Hochgeladen von')->placeholder('–'),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Dokument hochladen')
                    ->form([
                        Forms\Components\Select::make('kind')->label('Art')->options(EquipmentDocument::KINDS)->required(),
                        Forms\Components\TextInput::make('title')->label('Titel')->required()->maxLength(180),
                        Forms\Components\FileUpload::make('file')
                            ->label('Datei')
                            ->required()
                            ->storeFiles(false)
                            ->maxSize(EquipmentService::MAX_DOCUMENT_KB)
                            ->helperText('Zugelassene Dokumente und Bilder; maximal 25 MB. Dateien werden nur berechtigten Atemschutzgerätewarten angezeigt.'),
                    ])
                    ->using(function (array $data, RelationManager $livewire): EquipmentDocument {
                        $equipment = $livewire->getOwnerRecord();
                        $actor = auth()->user();
                        if (! $equipment instanceof Equipment || ! $actor instanceof User) {
                            abort(403);
                        }

                        return app(EquipmentService::class)->uploadDocument($equipment, $data, $actor);
                    }),
            ])
            ->actions([
                Actions\Action::make('download')
                    ->label('Öffnen')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (EquipmentDocument $record) => route('atemschutz.equipment-document', $record))
                    ->openUrlInNewTab(),
                Actions\DeleteAction::make()
                    ->label('Löschen')
                    ->requiresConfirmation()
                    ->action(function (EquipmentDocument $record, RelationManager $livewire): void {
                        $equipment = $livewire->getOwnerRecord();
                        $actor = auth()->user();
                        if (! $equipment instanceof Equipment || ! $actor instanceof User) {
                            abort(403);
                        }
                        app(EquipmentService::class)->deleteDocument($record, $equipment, $actor);
                    }),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }
}

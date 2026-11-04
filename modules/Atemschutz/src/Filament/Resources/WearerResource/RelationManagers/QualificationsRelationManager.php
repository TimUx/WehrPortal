<?php

namespace Modules\Atemschutz\src\Filament\Resources\WearerResource\RelationManagers;

use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Atemschutz\src\Models\Wearer;
use Modules\Atemschutz\src\Models\WearerQualification;
use Modules\Atemschutz\src\Services\WearerService;

class QualificationsRelationManager extends RelationManager
{
    protected static string $relationship = 'qualifications';

    protected static ?string $title = 'Qualifikationen';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('kind')->label('Art')
                    ->formatStateUsing(fn (string $state, WearerQualification $record) => $state === 'other'
                        ? $record->title
                        : (WearerQualification::KINDS[$state] ?? $state)),
                Tables\Columns\TextColumn::make('qualified_on')->label('Absolviert am')->date('d.m.Y')->placeholder('–'),
                Tables\Columns\TextColumn::make('valid_until')->label('Gültig bis')->date('d.m.Y')->placeholder('Unbefristet'),
                Tables\Columns\TextColumn::make('issuer')->label('Aussteller')->placeholder('–'),
                Tables\Columns\TextColumn::make('proof_filename')->label('Beleg')
                    ->placeholder('–')
                    ->url(fn (WearerQualification $record) => $record->proof_data ? route('atemschutz.qualification-proof', $record) : null)
                    ->openUrlInNewTab(),
                Tables\Columns\TextColumn::make('recorder.name')->label('Erfasst von')->placeholder('–'),
                Tables\Columns\IconColumn::make('is_legacy')->label('Übernommen')->boolean(),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Qualifikation erfassen')
                    ->form([
                        Forms\Components\Select::make('kind')->label('Art')->options(WearerQualification::KINDS)->required()
                            ->live(),
                        Forms\Components\TextInput::make('title')->label('Bezeichnung')->maxLength(180)
                            ->visible(fn (Get $get) => $get('kind') === 'other'),
                        Forms\Components\DatePicker::make('qualified_on')->label('Absolviert am')->native(false)->displayFormat('d.m.Y')
                            ->maxDate(today()),
                        Forms\Components\DatePicker::make('valid_until')->label('Gültig bis')->native(false)->displayFormat('d.m.Y')
                            ->afterOrEqual('qualified_on'),
                        Forms\Components\TextInput::make('issuer')->label('Aussteller')->maxLength(180),
                        Forms\Components\Textarea::make('notes')->label('Notiz')->rows(2)->maxLength(500),
                        Forms\Components\FileUpload::make('proof')
                            ->label('Beleg (optional)')
                            ->storeFiles(false)
                            ->maxSize(WearerService::MAX_PROOF_KB)
                            ->helperText('Zugelassene Dokumente und Bilder; maximal 25 MB. Keine G26.3-Unterlagen hochladen.'),
                    ])
                    ->using(function (array $data, RelationManager $livewire): WearerQualification {
                        $wearer = $livewire->getOwnerRecord();
                        $actor = auth()->user();
                        if (! $wearer instanceof Wearer || ! $actor instanceof User) {
                            abort(403);
                        }

                        return app(WearerService::class)->recordQualification($wearer, $data, $actor);
                    }),
            ])
            ->actions([
                Actions\Action::make('download')
                    ->label('Beleg öffnen')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (WearerQualification $record) => $record->proof_data !== null)
                    ->url(fn (WearerQualification $record) => route('atemschutz.qualification-proof', $record))
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([])
            ->defaultSort('qualified_on', 'desc');
    }
}

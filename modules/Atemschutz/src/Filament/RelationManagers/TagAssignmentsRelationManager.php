<?php

namespace Modules\Atemschutz\src\Filament\RelationManagers;

use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Atemschutz\src\Models\Equipment;
use Modules\Atemschutz\src\Models\TagAssignment;
use Modules\Atemschutz\src\Models\Wearer;
use Modules\Atemschutz\src\Services\TagAssignmentService;

class TagAssignmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'tagAssignments';

    protected static ?string $title = 'Kennzeichnungen';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('tag_uid')->label('Kennung')->fontFamily('mono'),
                Tables\Columns\TextColumn::make('standard')->label('Art')->formatStateUsing(fn (string $state) => strtoupper($state)),
                Tables\Columns\TextColumn::make('assigned_at')->label('Zugeordnet am')->dateTime('d.m.Y H:i')->timezone('Europe/Berlin'),
                Tables\Columns\TextColumn::make('is_legacy')->label('Quelle')->formatStateUsing(fn (bool $state) => $state ? 'Vorhandener Bestand' : 'Neu zugeordnet'),
                Tables\Columns\TextColumn::make('assigner.name')->label('Zugeordnet von')->placeholder('–'),
                Tables\Columns\TextColumn::make('deactivated_at')->label('Deaktiviert am')->dateTime('d.m.Y H:i')->timezone('Europe/Berlin')->placeholder('Aktiv'),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Kennzeichnung zuordnen')
                    ->form([
                        Forms\Components\TextInput::make('tag_uid')->label('RFID-/NFC-Kennung')->required()->maxLength(100)->autocomplete(false),
                        Forms\Components\Select::make('standard')->label('Art')->options([
                            'rfid' => 'RFID',
                            'nfc' => 'NFC',
                            'barcode' => 'Barcode',
                        ])->required()->default('rfid'),
                    ])
                    ->using(function (array $data, RelationManager $livewire): TagAssignment {
                        $owner = $livewire->getOwnerRecord();
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        return match (true) {
                            $owner instanceof Equipment => app(TagAssignmentService::class)->assignToEquipment($owner, $data['tag_uid'], $actor, $data['standard']),
                            $owner instanceof Wearer => app(TagAssignmentService::class)->assignToWearer($owner, $data['tag_uid'], $actor, $data['standard']),
                            default => throw new \LogicException('Tags can only be assigned to Atemschutz wearers or equipment.'),
                        };
                    }),
            ])
            ->actions([
                Actions\Action::make('deactivate')
                    ->label('Deaktivieren')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (TagAssignment $record) => $record->deactivated_at === null)
                    ->action(function (TagAssignment $record): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);
                        app(TagAssignmentService::class)->deactivate($record, $actor);
                    }),
            ])
            ->bulkActions([])
            ->defaultSort('assigned_at', 'desc');
    }
}

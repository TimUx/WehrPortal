<?php

namespace Modules\Atemschutz\src\Filament\Resources\EquipmentResource\RelationManagers;

use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Atemschutz\src\Models\Equipment;
use Modules\Atemschutz\src\Models\EquipmentTask;
use Modules\Atemschutz\src\Services\EquipmentService;

class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static ?string $title = 'Wartungsaufgaben';

    protected static ?string $modelLabel = 'Aufgabe';

    protected static ?string $pluralModelLabel = 'Wartungsaufgaben';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('title')->label('Aufgabe')->required()->maxLength(180),
            Forms\Components\DatePicker::make('due_on')->label('Fällig am')->required()->native(false)->displayFormat('d.m.Y'),
            Forms\Components\Textarea::make('notes')->label('Notizen')->maxLength(10000),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Aufgabe')->searchable(),
                Tables\Columns\TextColumn::make('due_on')->label('Fällig am')->date('d.m.Y')->sortable(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'open' ? 'Offen' : ($state === 'completed' ? 'Erledigt' : 'Abgebrochen'))
                    ->color(fn (string $state) => $state === 'open' ? 'warning' : 'success'),
                Tables\Columns\TextColumn::make('inspection.performed_on')->label('Prüfung')->date('d.m.Y')->placeholder('–'),
            ])
            ->headerActions([
                Actions\CreateAction::make()->label('Wartungsaufgabe planen')
                    ->using(function (array $data, RelationManager $livewire) {
                        $equipment = $livewire->getOwnerRecord();
                        $actor = auth()->user();
                        if (! $equipment instanceof Equipment || ! $actor instanceof User) {
                            abort(403);
                        }

                        return app(EquipmentService::class)->createTask($equipment, $data, $actor);
                    }),
            ])
            ->actions([
                Actions\Action::make('complete')
                    ->label('Erledigt')
                    ->icon('heroicon-o-check')
                    ->visible(fn (EquipmentTask $record) => $record->status === 'open')
                    ->form([
                        Forms\Components\Select::make('inspection_id')->label('Zugehörige Prüfung')
                            ->relationship(
                                'inspection',
                                'performed_on',
                                modifyQueryUsing: fn (Builder $query) => $query->where('equipment_id', $this->getOwnerRecord()->getKey()),
                            )
                            ->placeholder('Ohne Prüfprotokoll erledigen'),
                    ])
                    ->action(function (EquipmentTask $record, array $data): void {
                        $equipment = $record->equipment;
                        $actor = auth()->user();
                        if (! $equipment instanceof Equipment || ! $actor instanceof User) {
                            abort(403);
                        }

                        app(EquipmentService::class)->completeTask(
                            $record,
                            filled($data['inspection_id'] ?? null) ? $equipment->inspections()->findOrFail($data['inspection_id']) : null,
                            $actor,
                        );
                    }),
            ])
            ->bulkActions([])
            ->defaultSort('due_on');
    }
}

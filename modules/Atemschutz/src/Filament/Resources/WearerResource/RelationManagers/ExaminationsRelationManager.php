<?php

namespace Modules\Atemschutz\src\Filament\Resources\WearerResource\RelationManagers;

use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Atemschutz\src\Models\Wearer;
use Modules\Atemschutz\src\Models\WearerExamination;
use Modules\Atemschutz\src\Services\WearerService;

class ExaminationsRelationManager extends RelationManager
{
    protected static string $relationship = 'examinations';

    protected static ?string $title = 'G26.3-Untersuchungen';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('examined_on')->label('Untersucht am')->date('d.m.Y')->placeholder('–'),
                Tables\Columns\TextColumn::make('valid_until')->label('Gültig bis')->date('d.m.Y')->placeholder('–'),
                Tables\Columns\TextColumn::make('recorder.name')->label('Erfasst von')->placeholder('–'),
                Tables\Columns\TextColumn::make('created_at')->label('Erfasst am')->dateTime('d.m.Y H:i')->timezone('Europe/Berlin'),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Untersuchung erfassen')
                    ->form([
                        Forms\Components\DatePicker::make('examined_on')->label('Untersucht am')->required()->native(false)->displayFormat('d.m.Y')->maxDate(today()),
                        Forms\Components\DatePicker::make('valid_until')->label('Gültig bis')->required()->native(false)->displayFormat('d.m.Y')->afterOrEqual('examined_on'),
                    ])
                    ->using(function (array $data, RelationManager $livewire): WearerExamination {
                        $wearer = $livewire->getOwnerRecord();
                        if (! $wearer instanceof Wearer) {
                            throw new \LogicException('The examination relation must belong to a wearer.');
                        }
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        return app(WearerService::class)->recordExamination($wearer, $data, $actor);
                    }),
            ])
            ->actions([])
            ->bulkActions([])
            ->defaultSort('examined_on', 'desc');
    }
}

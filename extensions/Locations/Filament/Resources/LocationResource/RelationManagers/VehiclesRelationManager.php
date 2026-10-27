<?php

namespace Extensions\Locations\Filament\Resources\LocationResource\RelationManagers;

use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\LocationAccess;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

// Assigns existing records to this Standort without touching the module's own admin pages
class VehiclesRelationManager extends RelationManager
{
    protected static string $relationship = 'vehicles';

    protected static ?string $inverseRelationship = 'standort';

    protected static ?string $title = 'Fahrzeuge';

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageName): bool
    {
        $user = auth()->user();

        return $user && $ownerRecord instanceof Location && app(LocationAccess::class)->canManage($user, $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([Tables\Columns\TextColumn::make('name')->label('Name')->searchable()])
            ->headerActions([Actions\AssociateAction::make()->label('Zuordnen')->preloadRecordSelect()])
            ->recordActions([
                Actions\Action::make('moveLocation')
                    ->label('Standort wechseln')
                    ->icon('heroicon-o-arrow-right-circle')
                    ->schema([
                        Forms\Components\Select::make('location_id')->label('Neuer Standort')->required()
                            ->options(fn () => Location::active()->where('id', '!=', $this->getOwnerRecord()->getKey())->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')),
                    ])
                    ->action(function (Model $record, array $data): void {
                        $target = Location::active()->findOrFail((int) $data['location_id']);
                        abort_unless(app(LocationAccess::class)->canManage(auth()->user(), $target), 403);
                        $record->forceFill(['ext_location_id' => $target->getKey()])->save();
                    }),
            ]);
    }
}

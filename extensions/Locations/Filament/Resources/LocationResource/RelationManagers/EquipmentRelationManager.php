<?php

namespace Extensions\Locations\Filament\Resources\LocationResource\RelationManagers;

use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\LocationAccess;
use Filament\Actions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

// Assigns existing records to this Standort without touching the module's own admin pages
class EquipmentRelationManager extends RelationManager
{
    protected static string $relationship = 'equipment';

    protected static ?string $title = 'Ausrüstung';

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
            ->recordActions([Actions\DissociateAction::make()->label('Entfernen')]);
    }
}

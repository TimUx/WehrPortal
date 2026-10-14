<?php

namespace Modules\Ausruestung\src\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Ausruestung\src\Filament\Concerns\AusruestungArea;
use Modules\Ausruestung\src\Filament\Resources\LocationResource\Pages;
use Modules\Ausruestung\src\Filament\Resources\LocationResource\RelationManagers\CompartmentsRelationManager;
use Modules\Ausruestung\src\Models\Location;

/** Rooms, stores and vehicles, with their Geräteräume and Soll-Beladung */
class LocationResource extends Resource
{
    use AusruestungArea;

    protected static ?string $model = Location::class;

    protected static ?string $slug = 'ausruestung/standorte';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationLabel = 'Standorte & Fahrzeuge';

    protected static ?string $modelLabel = 'Standort';

    protected static ?string $pluralModelLabel = 'Standorte';

    protected static string|\UnitEnum|null $navigationGroup = 'Stammdaten';

    protected static ?int $navigationSort = 61;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('name')->label('Name')->required()->maxLength(255)->placeholder('z. B. HLF 20 oder Gerätehaus, Lager 1'),
            Forms\Components\Select::make('kind')->label('Art')->options(Location::KINDS)->default(Location::ROOM)->required()->native(false),
            Forms\Components\Textarea::make('description')->label('Beschreibung')->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Name')->searchable(),
                Tables\Columns\TextColumn::make('kind')->label('Art')->badge()->formatStateUsing(fn (string $state) => Location::KINDS[$state] ?? $state),
                Tables\Columns\TextColumn::make('compartments_count')->label('Geräteräume')->counts('compartments'),
                Tables\Columns\TextColumn::make('equipment_count')->label('Geräte')->counts('equipment'),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->reorderRecordsTriggerAction(fn (Actions\Action $action, bool $isReordering) => $action->button()->label($isReordering ? 'Fertig' : 'Reihenfolge ändern'))
            ->recordActions([Actions\EditAction::make()->label('Öffnen')]);
    }

    public static function getRelations(): array
    {
        return [CompartmentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLocations::route('/'),
            'create' => Pages\CreateLocation::route('/neu'),
            'edit' => Pages\EditLocation::route('/{record}/bearbeiten'),
        ];
    }
}

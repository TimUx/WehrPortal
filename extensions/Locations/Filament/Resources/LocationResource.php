<?php

namespace Extensions\Locations\Filament\Resources;

use Extensions\Locations\Filament\Resources\LocationResource\Pages;
use Extensions\Locations\Filament\Resources\LocationResource\RelationManagers;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\LocationAccess;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LocationResource extends Resource
{
    protected static ?string $slug = 'standorte';

    protected static ?string $model = Location::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Standorte';

    protected static ?string $modelLabel = 'Standort';

    protected static ?string $pluralModelLabel = 'Standorte';

    protected static ?int $navigationSort = 7;

    private static function isGlobalAdmin(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    /** Global admins and Standort-Admins; the latter only reach their own Standorte */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && $user->is_active && ($user->is_admin || app(LocationAccess::class)->isLocationAdmin($user));
    }

    public static function canCreate(): bool
    {
        return static::isGlobalAdmin();
    }

    public static function canEdit(Model $record): bool
    {
        $user = auth()->user();

        return $user && app(LocationAccess::class)->canManage($user, $record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::isGlobalAdmin() && ! $record->is_default;
    }

    public static function canDeleteAny(): bool
    {
        return static::isGlobalAdmin();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        return static::isGlobalAdmin() || ! $user
            ? $query
            : $query->whereIn('id', app(LocationAccess::class)->adminLocationIds($user));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\TextInput::make('name')->label('Name')->required()->maxLength(255),
            Forms\Components\TextInput::make('short_name')->label('Kurzname')->maxLength(50),
            Forms\Components\TextInput::make('slug')->label('Kennung')->required()->alphaDash()->maxLength(255)
                ->unique(ignoreRecord: true)->disabled(fn () => ! static::isGlobalAdmin()),
            Forms\Components\Toggle::make('is_active')->label('Aktiv')->default(true)->disabled(fn () => ! static::isGlobalAdmin()),
            Forms\Components\Toggle::make('is_default')->label('Hauptstandort')->disabled(fn () => ! static::isGlobalAdmin()),
            Forms\Components\TextInput::make('sort_order')->label('Reihenfolge')->numeric()->default(0)->disabled(fn () => ! static::isGlobalAdmin()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('short_name')->label('Kurzname'),
                Tables\Columns\TextColumn::make('members_count')->label('Mitglieder')->counts('members'),
                Tables\Columns\IconColumn::make('is_default')->label('Haupt')->boolean(),
                Tables\Columns\IconColumn::make('is_active')->label('Aktiv')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->recordActions([Actions\EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\MembersRelationManager::class,
            RelationManagers\VehiclesRelationManager::class,
            RelationManagers\EquipmentRelationManager::class,
        ];
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

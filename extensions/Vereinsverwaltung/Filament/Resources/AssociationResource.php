<?php

namespace Extensions\Vereinsverwaltung\Filament\Resources;

use Extensions\Locations\Models\Location;
use Extensions\Vereinsverwaltung\Filament\Resources\AssociationResource\Pages;
use Extensions\Vereinsverwaltung\Filament\Resources\AssociationResource\RelationManagers;
use Extensions\Vereinsverwaltung\Models\Association;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class AssociationResource extends Resource
{
    protected static ?string $slug = 'vereine';

    protected static ?string $model = Association::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static string|\UnitEnum|null $navigationGroup = 'Vereinsverwaltung';

    protected static ?string $navigationLabel = 'Vereine';

    protected static ?string $modelLabel = 'Verein';

    protected static ?string $pluralModelLabel = 'Vereine';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin && Schema::hasTable('x_locations') && parent::canAccess();
    }

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public static function canEdit(Model $record): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public static function canDelete(Model $record): bool
    {
        return (bool) auth()->user()?->is_admin
            && ! $record->members()->exists()
            && ! $record->applications()->exists();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\TextInput::make('name')->label('Vereinsname')->required()->maxLength(150)->unique(ignoreRecord: true),
            Forms\Components\Select::make('location_id')
                ->label('Standort / Ortsteil')
                ->options(fn () => Location::query()->withoutGlobalScopes()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->required()
                ->unique(ignoreRecord: true)
                ->searchable(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Verein')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('location.name')->label('Standort / Ortsteil')->sortable(),
                Tables\Columns\TextColumn::make('board_members_count')->label('Vorstandsmitglieder')->counts('boardMembers'),
            ])
            ->defaultSort('name')
            ->recordActions([Actions\EditAction::make(), Actions\DeleteAction::make()]);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\BoardMembersRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAssociations::route('/'),
            'create' => Pages\CreateAssociation::route('/neu'),
            'edit' => Pages\EditAssociation::route('/{record}/bearbeiten'),
        ];
    }
}

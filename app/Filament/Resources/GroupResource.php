<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GroupResource\Pages;
use App\Models\Group;
use App\Models\User;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\LocationAccess;
use Extensions\Locations\Services\LocationContext;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class GroupResource extends Resource
{
    protected static ?string $slug = 'abteilungen';

    protected static ?string $model = Group::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Abteilungen';

    protected static ?string $modelLabel = 'Abteilung';

    protected static ?string $pluralModelLabel = 'Abteilungen';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_active
            && ($user->is_admin || app(LocationAccess::class)->isLocationAdmin($user));
    }

    public static function canCreate(): bool
    {
        return self::canAccess();
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Group && self::canManage($record);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof Group && self::canManage($record);
    }

    public static function canDeleteAny(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        return $user instanceof User && ! $user->is_admin && Group::locationsAvailable()
            ? $query->whereIn('ext_location_id', app(LocationAccess::class)->adminLocationIds($user))
            : $query;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\Select::make('ext_location_id')
                ->label('Standort')
                ->options(fn (?Group $record) => self::locationOptions($record))
                ->default(fn () => Group::locationsAvailable()
                    ? app(LocationContext::class)->forUser(auth()->user())['current']
                    : null)
                ->required(fn () => Group::locationsAvailable())
                ->visible(fn () => Group::locationsAvailable())
                ->searchable()
                ->preload(),
            Forms\Components\TextInput::make('name')
                ->label('Name')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('sort_order')
                ->label('Reihenfolge')
                ->numeric()
                ->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('location.name')
                    ->label('Standort')
                    ->visible(fn () => Group::locationsAvailable())
                    ->sortable(),

                Tables\Columns\TextColumn::make('users_count')
                    ->label('Mitglieder')
                    ->counts('users'),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Reihenfolge')
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->reorderRecordsTriggerAction(fn (Actions\Action $action, bool $isReordering) => $action
                ->button()
                ->label($isReordering ? 'Fertig' : 'Reihenfolge ändern'))
            ->actions([
                Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGroups::route('/'),
            'create' => Pages\CreateGroup::route('/neu'),
            'edit' => Pages\EditGroup::route('/{record}/bearbeiten'),
        ];
    }

    private static function canManage(Group $group): bool
    {
        $user = auth()->user();
        if (! $user instanceof User || ! $user->is_active) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        $location = Location::query()->find($group->getAttribute('ext_location_id'));

        return $location && app(LocationAccess::class)->canManage($user, $location);
    }

    /** @return array<int, string> */
    private static function locationOptions(?Group $record): array
    {
        $query = Location::query()->where(fn ($query) => $query->where('is_active', true)
            ->when($record?->getAttribute('ext_location_id'), fn ($query, $id) => $query->orWhere('id', $id)));
        $user = auth()->user();

        if ($user instanceof User && ! $user->is_admin) {
            $query->whereIn('id', app(LocationAccess::class)->adminLocationIds($user));
        }

        return $query->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all();
    }
}

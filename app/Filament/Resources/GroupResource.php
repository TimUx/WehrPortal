<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\GroupResource\Pages;
use App\Models\Group;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class GroupResource extends Resource
{
    use AdminOnly;

    protected static ?string $slug = 'abteilungen';

    protected static ?string $model = Group::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Abteilungen';

    // Sub-menu of Kameraden
    protected static ?string $navigationParentItem = 'Kameraden';

    protected static ?string $modelLabel = 'Abteilung';

    protected static ?string $pluralModelLabel = 'Abteilungen';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
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
}

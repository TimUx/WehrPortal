<?php

namespace Modules\Kleiderkammer\src\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Kleiderkammer\src\Filament\Resources\ItemCategoryResource\Pages;
use Modules\Kleiderkammer\src\Models\ItemCategory;

class ItemCategoryResource extends Resource
{
    protected static ?string $slug = 'kategorien';

    protected static ?string $model = ItemCategory::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationLabel = 'Kategorien';

    protected static ?string $modelLabel = 'Kategorie';

    protected static ?string $pluralModelLabel = 'Kategorien';

    protected static string|\UnitEnum|null $navigationGroup = 'Katalog';

    protected static ?int $navigationSort = 20;

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

                Tables\Columns\TextColumn::make('item_types_count')
                    ->label('Artikeltypen')
                    ->counts('itemTypes'),

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
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListItemCategories::route('/'),
            'create' => Pages\CreateItemCategory::route('/neu'),
            'edit' => Pages\EditItemCategory::route('/{record}/bearbeiten'),
        ];
    }
}

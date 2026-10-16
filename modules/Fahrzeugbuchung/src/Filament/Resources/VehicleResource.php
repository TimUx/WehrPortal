<?php

namespace Modules\Fahrzeugbuchung\src\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Fahrzeugbuchung\src\Filament\Concerns\FahrzeugbuchungArea;
use Modules\Fahrzeugbuchung\src\Filament\Resources\VehicleResource\Pages;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

class VehicleResource extends Resource
{
    use FahrzeugbuchungArea;

    protected static ?string $slug = 'fahrzeugbuchung/fahrzeuge';

    protected static ?string $model = Vehicle::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'Fahrzeuge';

    protected static ?string $modelLabel = 'Fahrzeug';

    protected static ?string $pluralModelLabel = 'Fahrzeuge';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\TextInput::make('name')
                ->label('Name')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('short_name')
                ->label('Kurzname (für Handy)')
                ->maxLength(20)
                ->helperText('z. B. „MTW-A" – wird im Kalender auf dem Handy angezeigt'),

            Forms\Components\Select::make('type')
                ->label('Typ')
                ->options(['truck' => 'Fahrzeug (LKW/PKW)', 'trailer' => 'Anhänger'])
                ->default('truck')
                ->required(),

            Forms\Components\ColorPicker::make('color')
                ->label('Farbe im Kalender')
                ->required()
                ->regex('/^#[0-9a-fA-F]{6}$/')
                ->default('#3b82f6'),

            Forms\Components\Textarea::make('description')
                ->label('Beschreibung')
                ->rows(3)
                ->helperText('z. B. Sitzplätze, Führerscheinklasse (nur Info)'),

            Forms\Components\Toggle::make('is_active')
                ->label('Aktiv (buchbar)')
                ->default(true),

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
                Tables\Columns\ColorColumn::make('color')
                    ->label(''),

                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('short_name')
                    ->label('Kurzname')
                    ->placeholder('–'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktiv')
                    ->boolean(),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Reihenfolge')
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->actions([
                Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVehicles::route('/'),
            'create' => Pages\CreateVehicle::route('/neu'),
            'edit' => Pages\EditVehicle::route('/{record}/bearbeiten'),
        ];
    }
}

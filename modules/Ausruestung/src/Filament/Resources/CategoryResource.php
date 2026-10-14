<?php

namespace Modules\Ausruestung\src\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Ausruestung\src\Filament\Concerns\AusruestungArea;
use Modules\Ausruestung\src\Filament\Resources\CategoryResource\Pages;
use Modules\Ausruestung\src\Models\Category;

/** Kinds of equipment with default service life and inspection interval */
class CategoryResource extends Resource
{
    use AusruestungArea;

    protected static ?string $model = Category::class;

    protected static ?string $slug = 'ausruestung/kategorien';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationLabel = 'Kategorien & Fristen';

    protected static ?string $modelLabel = 'Kategorie';

    protected static ?string $pluralModelLabel = 'Kategorien';

    protected static string|\UnitEnum|null $navigationGroup = 'Stammdaten';

    protected static ?int $navigationSort = 60;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('name')->label('Name')->required()->unique(ignoreRecord: true)->maxLength(255)->columnSpanFull(),
            Forms\Components\TextInput::make('lifetime_years')->label('Max. Lebensdauer (Jahre)')->numeric()->minValue(1)->maxValue(100)
                ->helperText('Leer = unbegrenzt. Gezählt ab Herstellungsdatum.'),
            Forms\Components\TextInput::make('inspection_interval_months')->label('Prüfintervall (Monate)')->numeric()->minValue(1)->maxValue(240)
                ->helperText('Leer = keine wiederkehrende Prüfung.'),
            Forms\Components\TextInput::make('lifetime_basis')->label('Grundlage / Hinweis')->maxLength(255)->columnSpanFull()
                ->helperText('z. B. Herstellerangabe, DGUV-Information'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Kategorie')->searchable(),
                Tables\Columns\TextColumn::make('lifetime_years')->label('Lebensdauer')->formatStateUsing(fn ($state) => $state.' Jahre')->placeholder('unbegrenzt'),
                Tables\Columns\TextColumn::make('inspection_interval_months')->label('Prüfintervall')->formatStateUsing(fn ($state) => $state.' Monate')->placeholder('–'),
                Tables\Columns\TextColumn::make('lifetime_basis')->label('Grundlage')->wrap()->placeholder('–'),
                Tables\Columns\TextColumn::make('equipment_count')->label('Geräte')->counts('equipment'),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->reorderRecordsTriggerAction(fn (Actions\Action $action, bool $isReordering) => $action->button()->label($isReordering ? 'Fertig' : 'Reihenfolge ändern'))
            ->paginated(false)
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make()->modalDescription('Die Geräte dieser Kategorie bleiben erhalten, verlieren aber die Kategorie und deren Fristen.'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageCategories::route('/')];
    }
}

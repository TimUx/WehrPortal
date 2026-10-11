<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MemberFunctionResource\Pages;
use App\Models\MemberFunction;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/** What members do (Jugendwart, Gerätewart …), each with optional sub-functions; Abteilungen are where they belong */
class MemberFunctionResource extends Resource
{
    protected static ?string $model = MemberFunction::class;

    protected static ?string $slug = 'funktionen';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationLabel = 'Funktionen';

    // Sub-menu of Kameraden
    protected static ?string $navigationParentItem = 'Kameraden';

    protected static ?string $modelLabel = 'Funktion';

    protected static ?string $pluralModelLabel = 'Funktionen';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('name')
                ->label('Funktion')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            Forms\Components\Repeater::make('children')
                ->label('Unterfunktionen')
                ->relationship('children')
                ->schema([
                    Forms\Components\TextInput::make('name')->label('Unterfunktion')->required()->maxLength(255),
                ])
                ->orderColumn('sort_order')
                ->addActionLabel('Unterfunktion hinzufügen')
                ->reorderable()
                ->defaultItems(0)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->topLevel()->with('children'))
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Funktion')->searchable(),
                Tables\Columns\TextColumn::make('children.name')->label('Unterfunktionen')->badge()->placeholder('–'),
                Tables\Columns\TextColumn::make('members')
                    ->label('Mitglieder')
                    ->state(fn (MemberFunction $record) => $record->users()->count() + $record->children->sum(fn (MemberFunction $child) => $child->users()->count())),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->reorderRecordsTriggerAction(fn (Actions\Action $action, bool $isReordering) => $action
                ->button()
                ->label($isReordering ? 'Fertig' : 'Reihenfolge ändern'))
            ->paginated(false)
            ->actions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make()
                    ->modalDescription('Die Funktion und ihre Unterfunktionen werden gelöscht und bei allen Mitgliedern entfernt.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageMemberFunctions::route('/'),
        ];
    }
}

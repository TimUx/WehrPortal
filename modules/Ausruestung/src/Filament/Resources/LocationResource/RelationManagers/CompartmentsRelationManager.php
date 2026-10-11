<?php

namespace Modules\Ausruestung\src\Filament\Resources\LocationResource\RelationManagers;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Ausruestung\src\Models\Category;
use Modules\Ausruestung\src\Models\Compartment;

/** Geräteräume of a vehicle (G1, G2, Dach …) with their Soll-Beladung */
class CompartmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'compartments';

    protected static ?string $title = 'Geräteräume & Soll-Beladung';

    protected static ?string $modelLabel = 'Geräteraum';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('name')->label('Geräteraum / Fach')->required()->maxLength(255)->placeholder('z. B. G1 links')->columnSpanFull(),
            Forms\Components\Repeater::make('loadPlanItems')
                ->label('Soll-Beladung')
                ->relationship('loadPlanItems')
                ->schema([
                    Forms\Components\Select::make('category_id')->label('Kategorie')
                        ->options(fn () => Category::orderBy('sort_order')->pluck('name', 'id'))->required()->searchable()
                        ->distinct()->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                    Forms\Components\TextInput::make('quantity')->label('Anzahl')->numeric()->minValue(1)->default(1)->required(),
                ])
                ->columns(2)
                ->defaultItems(0)
                ->addActionLabel('Position hinzufügen')
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('loadPlanItems.category')->withCount('equipment'))
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Geräteraum'),
                Tables\Columns\TextColumn::make('plan')->label('Soll-Beladung')
                    ->state(fn (Compartment $record) => $record->loadPlanItems->map(fn ($i) => $i->quantity.'× '.$i->category->name)->all())
                    ->badge()->placeholder('–'),
                Tables\Columns\TextColumn::make('equipment_count')->label('Geräte darin'),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->paginated(false)
            ->headerActions([
                Actions\CreateAction::make()->label('Geräteraum hinzufügen')
                    ->mutateDataUsing(fn (array $data) => $data + ['sort_order' => (int) $this->getOwnerRecord()->compartments()->max('sort_order') + 1]),
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make()->modalDescription('Die Geräte darin bleiben am Standort, verlieren aber das Fach.'),
            ]);
    }
}

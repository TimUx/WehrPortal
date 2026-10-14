<?php

namespace Modules\Maengelmanagement\src\Filament\Resources;

use App\Models\User;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Maengelmanagement\src\Enums\DefectStatus;
use Modules\Maengelmanagement\src\Enums\Urgency;
use Modules\Maengelmanagement\src\Filament\Concerns\MaengelmanagementArea;
use Modules\Maengelmanagement\src\Filament\Resources\DefectResource\Pages;
use Modules\Maengelmanagement\src\Filament\Resources\DefectResource\RelationManagers\EventsRelationManager;
use Modules\Maengelmanagement\src\Models\Defect;
use Modules\Maengelmanagement\src\Models\Device;

class DefectResource extends Resource
{
    use MaengelmanagementArea;

    protected static ?string $model = Defect::class;

    protected static ?string $slug = 'maengelmanagement/maengel';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'Mängel';

    protected static ?string $modelLabel = 'Mangel';

    protected static ?string $pluralModelLabel = 'Mängel';

    protected static ?int $navigationSort = 10;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $open = Defect::where('status', DefectStatus::Open->value)->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Mangel')
                ->schema([
                    TextEntry::make('title')->label('Titel'),
                    TextEntry::make('subject')->label('Betrifft')->state(fn (Defect $record) => $record->subject()),
                    TextEntry::make('reporter.name')->label('Gemeldet von'),
                    TextEntry::make('created_at')->label('Gemeldet am')->dateTime('d.m.Y H:i')->timezone('Europe/Berlin'),
                    TextEntry::make('description')->label('Beschreibung')->placeholder('–')->columnSpanFull(),
                    TextEntry::make('photos')
                        ->label('Fotos')
                        ->state(fn (Defect $record) => $record->photos()->count().' – in der Mitgliederansicht zu sehen')
                        ->url(fn (Defect $record) => route('maengelmanagement.show', $record))
                        ->openUrlInNewTab(),
                ])
                ->columns(['default' => 1, 'md' => 2])
                ->columnSpanFull(),

            Section::make('Bearbeitung')
                ->schema([
                    Forms\Components\Select::make('status')->label('Status')->options(DefectStatus::options())->required()->native(false),
                    Forms\Components\Select::make('urgency')->label('Dringlichkeit')->options(Urgency::options())->required()->native(false),
                    Forms\Components\Select::make('assigned_to')
                        ->label('Zuständig')
                        ->options(fn () => User::active()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->placeholder('Niemand'),
                    Forms\Components\Textarea::make('note')
                        ->label('Notiz zur Änderung')
                        ->rows(2)
                        ->helperText('Erscheint im Verlauf und in der Mail an den Melder, z. B. „Ersatzteil bestellt“.')
                        ->dehydrated()
                        ->columnSpanFull(),
                ])
                ->columns(['default' => 1, 'md' => 3])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['device', 'vehicle', 'reporter', 'assignee']))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Gemeldet')->dateTime('d.m.Y H:i')->timezone('Europe/Berlin')->sortable(),
                Tables\Columns\TextColumn::make('title')->label('Mangel')->searchable()->limit(50),
                Tables\Columns\TextColumn::make('subject')->label('Betrifft')->state(fn (Defect $record) => $record->subject()),
                Tables\Columns\TextColumn::make('urgency')->label('Dringlichkeit')->badge()
                    ->formatStateUsing(fn (Urgency $state) => $state->label())
                    ->color(fn (Urgency $state) => $state->color())
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (DefectStatus $state) => $state->label())
                    ->color(fn (DefectStatus $state) => $state->color())
                    ->sortable(),
                Tables\Columns\TextColumn::make('reporter.name')->label('Melder')->searchable(),
                Tables\Columns\TextColumn::make('assignee.name')->label('Zuständig')->placeholder('–'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Status')->options(DefectStatus::options())
                    ->default(null),
                Tables\Filters\SelectFilter::make('urgency')->label('Dringlichkeit')->options(Urgency::options()),
                Tables\Filters\SelectFilter::make('device_id')->label('Gerät')
                    ->options(fn () => Device::orderBy('name')->pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('vehicle_id')->label('Fahrzeug')
                    ->options(fn () => Vehicle::orderBy('name')->pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('assigned_to')->label('Zuständig')
                    ->options(fn () => User::active()->orderBy('name')->pluck('name', 'id')),
                Tables\Filters\TernaryFilter::make('active')->label('Anzeigen')
                    ->placeholder('Alle')->trueLabel('Nur offene und in Bearbeitung')->falseLabel('Nur abgeschlossene')
                    ->default(true)
                    ->queries(
                        true: fn ($query) => $query->active(),
                        false: fn ($query) => $query->whereNotIn('status', [DefectStatus::Open->value, DefectStatus::InProgress->value]),
                        blank: fn ($query) => $query,
                    ),
            ], layout: Tables\Enums\FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(['default' => 1, 'sm' => 2, 'lg' => 5])
            ->recordUrl(fn (Defect $record) => static::getUrl('edit', ['record' => $record]))
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [EventsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDefects::route('/'),
            'edit' => Pages\EditDefect::route('/{record}/bearbeiten'),
        ];
    }
}

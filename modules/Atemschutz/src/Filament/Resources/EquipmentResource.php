<?php

namespace Modules\Atemschutz\src\Filament\Resources;

use App\Models\User;
use Extensions\Locations\Models\Location;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema as DbSchema;
use Modules\Atemschutz\src\Filament\RelationManagers\TagAssignmentsRelationManager;
use Modules\Atemschutz\src\Filament\Resources\EquipmentResource\Pages;
use Modules\Atemschutz\src\Filament\Resources\EquipmentResource\RelationManagers;
use Modules\Atemschutz\src\Models\Equipment;
use Modules\Atemschutz\src\Models\EquipmentType;
use Modules\Atemschutz\src\Services\MonitorService;

class EquipmentResource extends Resource
{
    protected static ?string $model = Equipment::class;

    protected static ?string $slug = 'atemschutz/ausruestung';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static string|\UnitEnum|null $navigationGroup = 'Atemschutz';

    protected static ?string $navigationLabel = 'Atemschutzausrüstung';

    protected static ?string $modelLabel = 'Atemschutzgerät';

    protected static ?string $pluralModelLabel = 'Atemschutzausrüstung';

    protected static ?int $navigationSort = 20;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_active && MonitorService::canManageEquipment($user);
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Equipment && static::canAccess();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['type', 'components.component.type', 'componentOf.equipment']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Stammdaten')
                ->schema([
                    Forms\Components\TextInput::make('name')->label('Bezeichnung')->required()->maxLength(180)->columnSpan(2),
                    Forms\Components\TextInput::make('code')->label('Inventarcode')->disabled()->dehydrated(false)
                        ->placeholder('Wird beim Anlegen automatisch vergeben'),
                    Forms\Components\TextInput::make('device_number')->label('Gerätenummer')->maxLength(80)->unique(ignoreRecord: true),
                    Forms\Components\Select::make('type_id')->label('Gerätetyp')
                        ->options(fn () => EquipmentType::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                        ->searchable()->live()->required()
                        ->afterStateUpdated(fn (Set $set) => $set('specifications', [])),
                    Forms\Components\TextInput::make('manufacturer')->label('Hersteller')->maxLength(150),
                    Forms\Components\TextInput::make('model')->label('Modell / Bauart')->maxLength(150),
                    Forms\Components\TextInput::make('serial_number')->label('Seriennummer')->maxLength(150),
                    Forms\Components\DatePicker::make('manufactured_at')->label('Herstellungsdatum')->native(false)->displayFormat('d.m.Y'),
                    Forms\Components\DatePicker::make('purchased_at')->label('Kaufdatum')->native(false)->displayFormat('d.m.Y'),
                    Forms\Components\DatePicker::make('commissioned_at')->label('In Betrieb seit')->native(false)->displayFormat('d.m.Y'),
                    TextEntry::make('status')->label('Status')->badge()
                        ->formatStateUsing(fn (?string $state) => self::statusLabel($state))
                        ->hiddenOn('create'),
                ])
                ->columns(['default' => 1, 'md' => 3])
                ->columnSpanFull(),

            Section::make('Atemschutzmasken-Daten')
                ->schema([
                    Forms\Components\TextInput::make('specifications.mask_type')->label('Maskentyp')->maxLength(120),
                    Forms\Components\TextInput::make('specifications.size')->label('Größe')->maxLength(80),
                    Forms\Components\TextInput::make('specifications.visor')->label('Sichtscheibe')->maxLength(120),
                    Forms\Components\TextInput::make('specifications.connection')->label('Anschluss')->maxLength(120),
                    Forms\Components\TextInput::make('specifications.material')->label('Material')->maxLength(120),
                ])
                ->columns(['default' => 1, 'md' => 3])
                ->visible(fn (Get $get) => self::typeFamily($get('type_id')) === 'mask')
                ->columnSpanFull(),

            Section::make('Pressluftatmer-Daten')
                ->schema([
                    Forms\Components\TextInput::make('specifications.design')->label('Bauart')->maxLength(120),
                    Forms\Components\TextInput::make('specifications.harness')->label('Tragesystem')->maxLength(120),
                    Forms\Components\TextInput::make('specifications.pressure_reducer')->label('Druckminderer')->maxLength(120),
                    Forms\Components\TextInput::make('specifications.warning_device')->label('Warnsignal / Restdruckwarneinrichtung')->maxLength(120),
                ])
                ->columns(['default' => 1, 'md' => 2])
                ->visible(fn (Get $get) => self::typeFamily($get('type_id')) === 'apparatus')
                ->columnSpanFull(),

            Section::make('Atemluftflaschen-Daten')
                ->schema([
                    Forms\Components\TextInput::make('specifications.material')->label('Material')->maxLength(120),
                    Forms\Components\TextInput::make('specifications.construction')->label('Bauart')->maxLength(120),
                    Forms\Components\TextInput::make('specifications.volume_l')->label('Volumen')->numeric()->minValue(0.1)->maxValue(100)->suffix('l'),
                    Forms\Components\TextInput::make('specifications.working_pressure_bar')->label('Betriebsdruck')->numeric()->minValue(1)->suffix('bar'),
                    Forms\Components\TextInput::make('specifications.test_pressure_bar')->label('Prüfdruck')->numeric()->minValue(1)->suffix('bar'),
                    Forms\Components\TextInput::make('specifications.valve_type')->label('Ventiltyp')->maxLength(120),
                    Forms\Components\TextInput::make('fill_pressure_bar')->label('Aktueller Fülldruck')->numeric()->minValue(0)->suffix('bar'),
                    Forms\Components\DatePicker::make('last_filled_on')->label('Zuletzt gefüllt am')->native(false)->displayFormat('d.m.Y'),
                ])
                ->columns(['default' => 1, 'md' => 3])
                ->visible(fn (Get $get) => self::typeFamily($get('type_id')) === 'cylinder')
                ->columnSpanFull(),

            Section::make('Prüffrist und Prüfgrundlage')
                ->schema([
                    Forms\Components\DatePicker::make('next_inspection_at')->label('Nächste Prüfung')->native(false)->displayFormat('d.m.Y'),
                    Forms\Components\Textarea::make('inspection_basis')->label('Geprüfte Hersteller-/Fachgrundlage')
                        ->rows(3)->maxLength(10000)->columnSpan(2),
                ])
                ->columns(['default' => 1, 'md' => 3])
                ->columnSpanFull(),

            Section::make('Standort und Kennzeichnung')
                ->schema([
                    Forms\Components\Select::make('ext_location_id')->label('Organisatorischer Standort')
                        ->options(fn () => config('locations.enabled', false) && DbSchema::hasTable('x_locations')
                            ? Location::query()->orderBy('name')->pluck('name', 'id')
                            : [])
                        ->searchable()->nullable()
                        ->visible(fn () => config('locations.enabled', false) && DbSchema::hasTable('x_locations'))
                        ->dehydrated(fn () => config('locations.enabled', false) && DbSchema::hasTable('x_locations')),
                    Forms\Components\TextInput::make('physical_location')->label('Physischer Lagerort / Werkstatt')->maxLength(180),
                    Forms\Components\Select::make('component_ids')->label('Komponenten')
                        ->multiple()->searchable()->dehydrated()
                        ->default(fn (?Equipment $record) => $record?->components()->pluck('component_equipment_id')->all() ?? [])
                        ->options(fn (?Equipment $record) => Equipment::query()
                            ->when($record, fn (Builder $query) => $query->where('id', '!=', $record->id))
                            ->where(function (Builder $query) use ($record) {
                                $query->whereDoesntHave('componentOf')
                                    ->when($record, fn (Builder $query) => $query->orWhereHas(
                                        'componentOf',
                                        fn (Builder $relation) => $relation->where('equipment_id', $record->id),
                                    ));
                            })
                            ->orderBy('code')
                            ->pluck('name', 'id')),
                    Forms\Components\Textarea::make('notes')->label('Notizen')->rows(3)->maxLength(10000)->columnSpanFull(),
                ])
                ->columns(['default' => 1, 'md' => 3])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['type', 'componentOf.equipment']))
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Code')->fontFamily('mono')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('name')->label('Bezeichnung')->searchable(['name', 'device_number', 'serial_number', 'manufacturer', 'model'])->sortable()
                    ->description(fn (Equipment $record) => collect([$record->manufacturer, $record->model, $record->serial_number])->filter()->implode(' · ')),
                Tables\Columns\TextColumn::make('type.name')->label('Gerätetyp')->sortable(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => self::statusLabel($state))
                    ->color(fn (string $state) => match ($state) {
                        Equipment::STATUS_AVAILABLE => 'success',
                        Equipment::STATUS_IN_REPAIR => 'warning',
                        Equipment::STATUS_DEFECTIVE => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('operational_ready')->label('Einsatzbereit')->boolean()
                    ->state(fn (Equipment $record) => $record->isOperationallyReady()),
                Tables\Columns\TextColumn::make('fill_pressure_bar')->label('Fülldruck')->suffix(' bar')->placeholder('–')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('next_inspection_at')->label('Nächste Prüfung')->date('d.m.Y')->sortable()->placeholder('–')
                    ->color(fn (Equipment $record) => $record->next_inspection_at && Carbon::parse($record->next_inspection_at)->isBefore(today()) ? 'danger' : null),
                Tables\Columns\TextColumn::make('physical_location')->label('Lagerort')->placeholder('–')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type_id')->label('Gerätetyp')
                    ->relationship('type', 'name')->multiple(),
                Tables\Filters\SelectFilter::make('status')->label('Status')->options([
                    Equipment::STATUS_AVAILABLE => 'Einsatzbereit',
                    Equipment::STATUS_IN_REPAIR => 'In Reparatur',
                    Equipment::STATUS_DEFECTIVE => 'Defekt / gesperrt',
                    Equipment::STATUS_RETIRED => 'Ausgesondert',
                ])->multiple(),
                Tables\Filters\Filter::make('inspection_overdue')->label('Prüfung überfällig')
                    ->query(fn (Builder $query) => $query->whereNotNull('next_inspection_at')->whereDate('next_inspection_at', '<', today())),
            ])
            ->defaultSort('code')
            ->actions([
                Actions\EditAction::make()->label('Öffnen'),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Noch keine Atemschutzgeräte erfasst')
            ->emptyStateDescription('Der Atemschutzbestand startet leer. Es werden keine Geräte aus der allgemeinen Ausrüstung übernommen.');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\InspectionsRelationManager::class,
            RelationManagers\TasksRelationManager::class,
            RelationManagers\EventsRelationManager::class,
            RelationManagers\DocumentsRelationManager::class,
            TagAssignmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEquipment::route('/'),
            'create' => Pages\CreateEquipment::route('/neu'),
            'edit' => Pages\EditEquipment::route('/{record}/bearbeiten'),
        ];
    }

    private static function typeFamily(mixed $typeId): ?string
    {
        return filled($typeId)
            ? EquipmentType::query()->whereKey($typeId)->value('family')
            : null;
    }

    private static function statusLabel(?string $status): string
    {
        return match ($status) {
            Equipment::STATUS_AVAILABLE => 'Einsatzbereit',
            Equipment::STATUS_IN_REPAIR => 'In Reparatur',
            Equipment::STATUS_DEFECTIVE => 'Defekt / gesperrt',
            Equipment::STATUS_RETIRED => 'Ausgesondert',
            default => '–',
        };
    }
}

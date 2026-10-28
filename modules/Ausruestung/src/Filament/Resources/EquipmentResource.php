<?php

namespace Modules\Ausruestung\src\Filament\Resources;

use App\Models\User;
use Extensions\Locations\Models\Location as Standort;
use Extensions\Locations\Services\LocationAccess;
use Extensions\Locations\Services\LocationContext;
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
use Illuminate\Support\Collection;
use Modules\Ausruestung\src\Enums\EquipmentStatus;
use Modules\Ausruestung\src\Filament\Concerns\AusruestungArea;
use Modules\Ausruestung\src\Filament\Resources\EquipmentResource\Pages;
use Modules\Ausruestung\src\Filament\Resources\EquipmentResource\RelationManagers;
use Modules\Ausruestung\src\Filament\Support\EquipmentActions;
use Modules\Ausruestung\src\Models\Category;
use Modules\Ausruestung\src\Models\Compartment;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Ausruestung\src\Models\Location;
use Modules\Ausruestung\src\Services\EquipmentService;

class EquipmentResource extends Resource
{
    use AusruestungArea;

    protected static ?string $model = Equipment::class;

    protected static ?string $slug = 'ausruestung/geraete';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationLabel = 'Geräte';

    protected static ?string $modelLabel = 'Gerät';

    protected static ?string $pluralModelLabel = 'Geräte';

    protected static ?int $navigationSort = 10;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $locationIds = EquipmentService::equipmentManagementScope($user);

        return $locationIds === null ? $query : ($locationIds === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('ext_location_id', $locationIds));
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->canManageAusruestung() ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        $user = auth()->user();

        return $user instanceof User && $record instanceof Equipment
            && EquipmentService::canManageEquipment($user, $record);
    }

    public static function form(Schema $schema): Schema
    {
        $components = [
            Section::make('Gerät')
                ->schema([
                    Forms\Components\TextInput::make('name')->label('Bezeichnung')->required()->maxLength(255)->columnSpan(2),
                    Forms\Components\TextInput::make('code')->label('Code')->unique(ignoreRecord: true)->maxLength(100)
                        ->placeholder('Wird vergeben (AR-0001)')
                        ->helperText('Steht im QR-Code. Nachträglich geändert, passen gedruckte Etiketten nicht mehr.'),
                    Forms\Components\Select::make('category_id')->label('Kategorie')
                        ->options(fn () => Category::orderBy('sort_order')->orderBy('name')->pluck('name', 'id'))
                        ->searchable()->live(),
                    Forms\Components\TextInput::make('manufacturer')->label('Hersteller')->maxLength(255)
                        ->datalist(fn () => Equipment::whereNotNull('manufacturer')->distinct()->orderBy('manufacturer')->pluck('manufacturer')->all()),
                    Forms\Components\TextInput::make('model')->label('Modell / Typ')->maxLength(255),
                    Forms\Components\TextInput::make('serial_number')->label('Seriennummer')->maxLength(255),
                    Forms\Components\TextInput::make('batch_number')->label('Charge / Los')->maxLength(255),
                    Forms\Components\TextInput::make('barcode')->label('Barcode des Herstellers')->maxLength(255)
                        ->helperText('Optional; lässt sich wie der eigene Code scannen.'),
                    Forms\Components\TextInput::make('rfid')->label('RFID-/NFC-Tag')->maxLength(64)
                        ->unique(ignoreRecord: true)->validationMessages(['unique' => 'Dieser Tag gehört schon zu einem anderen Gerät.'])
                        ->helperText('Kennung des Tags (z. B. 04:A2:3B:9C …); auch für Geräte ohne Platz für ein Etikett. Am Handy per NFC auf der Geräteseite anlernen.'),
                ])
                ->columns(['default' => 1, 'md' => 3])
                ->columnSpanFull(),

            Section::make('Daten, Lebensdauer und Prüffristen')
                ->schema([
                    Forms\Components\DatePicker::make('manufactured_at')->label('Herstellungsdatum')->native(false)->displayFormat('d.m.Y')->maxDate(today())
                        ->helperText('Basis für die Lebensdauer; ohne Datum zählt die Inbetriebnahme.'),
                    Forms\Components\DatePicker::make('commissioned_at')->label('In Betrieb seit')->native(false)->displayFormat('d.m.Y'),
                    Forms\Components\DatePicker::make('purchased_at')->label('Kaufdatum')->native(false)->displayFormat('d.m.Y'),
                    Forms\Components\TextInput::make('purchase_price')->label('Kaufpreis')->numeric()->minValue(0)->suffix('€'),
                    Forms\Components\TextInput::make('lifetime_years')->label('Lebensdauer (Jahre)')->numeric()->minValue(1)->maxValue(100)
                        ->placeholder(fn (Get $get) => static::categoryValue($get('category_id'), 'lifetime_years') ?? 'unbegrenzt')
                        ->helperText(fn (Get $get) => 'Leer = Vorgabe der Kategorie'.(($basis = static::categoryValue($get('category_id'), 'lifetime_basis')) ? ' ('.$basis.')' : '').'.'),
                    Forms\Components\TextInput::make('inspection_interval_months')->label('Prüfintervall (Monate)')->numeric()->minValue(1)->maxValue(240)
                        ->placeholder(fn (Get $get) => static::categoryValue($get('category_id'), 'inspection_interval_months') ?? 'keine Prüfung')
                        ->helperText('Leer = Vorgabe der Kategorie.'),
                    Forms\Components\DatePicker::make('next_inspection_at')->label('Nächste Prüfung')->native(false)->displayFormat('d.m.Y')
                        ->helperText('Wird beim Eintragen einer Prüfung neu berechnet.'),
                ])
                ->columns(['default' => 1, 'md' => 3])
                ->columnSpanFull(),

            Section::make('Lagerort und Beladung')
                ->schema([
                    Forms\Components\Select::make('location_id')->label('Lagerort / Fahrzeug')
                        ->options(fn (Get $get) => Location::query()
                            ->when(
                                config('locations.enabled', false),
                                fn (Builder $query) => $query
                                    ->whereIn('ext_location_id', EquipmentService::manageableLocationIds(auth()->user()))
                                    ->where('ext_location_id', $get('ext_location_id')),
                            )
                            ->orderBy('sort_order')->orderBy('name')->pluck('name', 'id'))
                        ->searchable()->live()
                        ->afterStateUpdated(fn (Set $set) => $set('compartment_id', null)),
                    Forms\Components\Select::make('compartment_id')->label('Geräteraum / Fach')
                        ->options(fn (Get $get) => Compartment::where('location_id', $get('location_id'))->orderBy('sort_order')->pluck('name', 'id'))
                        ->visible(fn (Get $get) => filled($get('location_id')) && Compartment::where('location_id', $get('location_id'))->exists()),
                    TextEntry::make('status')->label('Status')->badge()
                        ->formatStateUsing(fn (EquipmentStatus $state) => $state->label())
                        ->color(fn (EquipmentStatus $state) => $state->color())
                        ->hiddenOn('create'),
                    Forms\Components\Textarea::make('notes')->label('Notizen')->rows(2)->columnSpanFull(),
                ])
                ->columns(['default' => 1, 'md' => 3])
                ->columnSpanFull(),
        ];

        if (config('locations.enabled', false)) {
            $components[] = Section::make('Organisatorischer Standort')
                ->schema([
                    Forms\Components\Select::make('ext_location_id')
                        ->label('Standort')
                        ->options(fn () => self::standortOptions())
                        ->default(function () {
                            $options = self::standortOptions();
                            $current = app(LocationContext::class)->forUser(auth()->user())['current'];

                            return $current !== null && isset($options[$current]) ? $current : array_key_first($options);
                        })
                        ->dehydrated()
                        ->required()
                        ->searchable(),
                ])
                ->columnSpanFull();
        }

        return $schema->components($components);
    }

    public static function table(Table $table): Table
    {
        $item = fn ($record) => $record;
        $columns = [
            Tables\Columns\TextColumn::make('code')->label('Code')->fontFamily('mono')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('name')->label('Bezeichnung')->searchable(['name', 'serial_number', 'barcode', 'rfid', 'manufacturer', 'model'])->sortable()
                ->description(fn (Equipment $record) => collect([$record->manufacturer, $record->model, $record->serial_number ? 'SN '.$record->serial_number : null])->filter()->implode(' · ')),
            Tables\Columns\TextColumn::make('category.name')->label('Kategorie')->placeholder('–')->toggleable(),
            Tables\Columns\TextColumn::make('place')->label('Lagerort')->state(fn (Equipment $record) => $record->placeLabel()),
            Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                ->formatStateUsing(fn (EquipmentStatus $state) => $state->label())
                ->color(fn (EquipmentStatus $state) => $state->color()),
            Tables\Columns\TextColumn::make('next_inspection_at')->label('Nächste Prüfung')->date('d.m.Y')->sortable()->placeholder('–')
                ->color(fn (Equipment $record) => $record->inspectionOverdue() ? 'danger' : ($record->next_inspection_at?->lte(today()->addDays(30)) ? 'warning' : null)),
            Tables\Columns\TextColumn::make('lifetime_end')->label('Lebensdauer bis')
                ->state(fn (Equipment $record) => $record->lifetimeEndsAt()?->format('d.m.Y'))
                ->color(fn (Equipment $record) => $record->lifetimeExpired() ? 'danger' : null)
                ->placeholder('–')->toggleable(),
        ];
        if (config('locations.enabled', false)) {
            $columns[] = Tables\Columns\TextColumn::make('standort.name')->label('Standort')->placeholder('–');
        }

        $filters = [
            Tables\Filters\SelectFilter::make('category_id')->label('Kategorie')
                ->options(fn () => Category::orderBy('sort_order')->pluck('name', 'id'))->multiple(),
            Tables\Filters\SelectFilter::make('location_id')->label('Lagerort / Fahrzeug')
                ->options(fn () => Location::query()
                    ->when(config('locations.enabled', false), fn (Builder $query) => $query->whereIn(
                        'ext_location_id',
                        EquipmentService::manageableLocationIds(auth()->user()),
                    ))
                    ->orderBy('sort_order')->pluck('name', 'id'))->multiple(),
        ];
        if (config('locations.enabled', false)) {
            $filters[] = Tables\Filters\SelectFilter::make('ext_location_id')->label('Standort')
                ->options(fn () => Standort::query()
                    ->whereIn('id', app(LocationAccess::class)->visibleLocationIds(auth()->user()))
                    ->when(
                        ($scope = EquipmentService::equipmentManagementScope(auth()->user())) !== null,
                        fn (Builder $query) => $query->whereIn('id', $scope),
                    )
                    ->orderBy('sort_order')->orderBy('name')->pluck('name', 'id'))->multiple();
        }
        array_push(
            $filters,
            Tables\Filters\SelectFilter::make('status')->label('Status')->options(EquipmentStatus::options())->multiple(),
            Tables\Filters\Filter::make('in_service')->label('Ohne ausgesonderte')->default()
                ->query(fn (Builder $query) => $query->inService()),
            Tables\Filters\Filter::make('inspection_due')->label('Prüfung in 30 Tagen fällig')
                ->query(fn (Builder $query) => $query->inspectionDue(30)),
            Tables\Filters\Filter::make('lifetime_expired')->label('Lebensdauer abgelaufen')
                ->query(fn (Builder $query) => static::whereLifetimeEndsBefore($query, today()->addDay())),
        );

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(config('locations.enabled', false)
                ? ['category', 'location', 'compartment.location', 'standort']
                : ['category', 'location', 'compartment.location']))
            ->columns($columns)
            ->defaultSort('code')
            ->filters($filters, layout: Tables\Enums\FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(['default' => 1, 'sm' => 2, 'lg' => 3])
            ->actions([
                Actions\EditAction::make()->label('Öffnen'),
                Actions\ActionGroup::make([
                    EquipmentActions::inspect($item),
                    EquipmentActions::lend($item),
                    EquipmentActions::logUse($item),
                    EquipmentActions::reportDamage($item),
                    EquipmentActions::qr($item),
                    EquipmentActions::retire($item),
                    EquipmentActions::reactivate($item),
                ]),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\BulkAction::make('inspectAll')
                        ->label('Prüfung eintragen (bestanden)')
                        ->icon('heroicon-o-clipboard-document-check')
                        ->schema([
                            Forms\Components\DatePicker::make('inspected_at')->label('Geprüft am')->default(today())->maxDate(today())->required()->native(false)->displayFormat('d.m.Y'),
                            Forms\Components\Textarea::make('notes')->label('Bemerkung')->rows(2),
                        ])
                        ->action(function (Collection $records, array $data) {
                            foreach ($records->reject(fn (Equipment $e) => $e->status === EquipmentStatus::Retired) as $equipment) {
                                $service = app(EquipmentService::class);
                                $service->assertCanManageEquipment(auth()->user(), $equipment);
                                $service->recordInspection($equipment, auth()->user(), Carbon::parse($data['inspected_at']), true, $data['notes'] ?? null);
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    Actions\BulkAction::make('move')
                        ->label('Lagerort ändern')
                        ->icon('heroicon-o-map-pin')
                        ->schema([
                            Forms\Components\Select::make('location_id')->label('Lagerort / Fahrzeug')
                                ->options(fn () => Location::query()
                                    ->when(config('locations.enabled', false), fn (Builder $query) => $query->whereIn(
                                        'ext_location_id',
                                        EquipmentService::manageableLocationIds(auth()->user()),
                                    ))
                                    ->orderBy('sort_order')->pluck('name', 'id'))->required()->live(),
                            Forms\Components\Select::make('compartment_id')->label('Geräteraum / Fach')
                                ->options(fn (Get $get) => Compartment::where('location_id', $get('location_id'))->orderBy('sort_order')->pluck('name', 'id')),
                        ])
                        ->action(function (Collection $records, array $data) {
                            foreach ($records as $equipment) {
                                app(EquipmentService::class)->move($equipment, auth()->user(), (int) $data['location_id'], $data['compartment_id'] ? (int) $data['compartment_id'] : null);
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    ...(config('locations.enabled', false) ? [
                        Actions\BulkAction::make('moveStandort')
                            ->label('Standort ändern')
                            ->icon('heroicon-o-map-pin')
                            ->schema([
                                Forms\Components\Select::make('ext_location_id')->label('Standort')
                                    ->options(fn () => Standort::query()
                                        ->whereIn('id', app(LocationAccess::class)->visibleLocationIds(auth()->user()))
                                        ->when(
                                            ($scope = EquipmentService::equipmentManagementScope(auth()->user())) !== null,
                                            fn (Builder $query) => $query->whereIn('id', $scope),
                                        )
                                        ->orderBy('sort_order')->orderBy('name')->pluck('name', 'id'))
                                    ->required(),
                            ])
                            ->action(function (Collection $records, array $data) {
                                foreach ($records as $equipment) {
                                    app(EquipmentService::class)->moveStandort($equipment, auth()->user(), (int) $data['ext_location_id']);
                                }
                            })
                            ->deselectRecordsAfterCompletion(),
                    ] : []),
                ]),
            ]);
    }

    /** Items in service whose service life ends before the given day */
    public static function whereLifetimeEndsBefore(Builder $query, Carbon $day): Builder
    {
        return $query->inService()->whereRaw(
            'coalesce(manufactured_at, commissioned_at) + make_interval(years => coalesce(ar_equipment.lifetime_years, (select c.lifetime_years from ar_categories c where c.id = ar_equipment.category_id))::int) < ?',
            [$day->toDateString()],
        );
    }

    private static function categoryValue(mixed $categoryId, string $field): mixed
    {
        return $categoryId ? Category::whereKey($categoryId)->value($field) : null;
    }

    /** @return array<int, string> */
    private static function standortOptions(): array
    {
        $user = auth()->user();
        if (! $user) {
            return [];
        }
        $query = Standort::query()->whereIn('id', app(LocationAccess::class)->visibleLocationIds($user));
        $scope = EquipmentService::equipmentManagementScope($user);
        if ($scope !== null) {
            $query->whereIn('id', $scope);
        }

        return $query->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\EventsRelationManager::class,
            RelationManagers\InspectionsRelationManager::class,
            RelationManagers\LoansRelationManager::class,
            RelationManagers\DamagesRelationManager::class,
            RelationManagers\RecallsRelationManager::class,
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
}

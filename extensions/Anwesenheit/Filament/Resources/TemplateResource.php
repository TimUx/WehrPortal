<?php

namespace Extensions\Anwesenheit\Filament\Resources;

use App\Models\User;
use Extensions\Anwesenheit\Filament\Resources\TemplateResource\Pages;
use Extensions\Anwesenheit\Models\AttendanceList;
use Extensions\Anwesenheit\Models\AttendanceTemplate;
use Extensions\Anwesenheit\Services\AttendanceService;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource as FilamentResource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Templates (Vorlagen) use Attendance-specific ownership checks: unassigned templates
 * are not shared, and Standort administration remains limited to managed Standorte.
 *
 * @extends FilamentResource<AttendanceTemplate>
 */
class TemplateResource extends FilamentResource
{
    protected static ?string $slug = 'anwesenheit/vorlagen';

    protected static ?string $model = AttendanceTemplate::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-duplicate';

    protected static string|\UnitEnum|null $navigationGroup = 'Anwesenheit';

    protected static ?string $navigationLabel = 'Vorlagen';

    protected static ?string $modelLabel = 'Vorlage';

    protected static ?string $pluralModelLabel = 'Vorlagen';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && AttendanceService::canManage($user);
    }

    public static function canEdit(Model $record): bool
    {
        $user = auth()->user();

        return $user instanceof User && AttendanceService::canManageGroupAt($user, $record->ext_location_id, $record->group_id);
    }

    public static function canDelete(Model $record): bool
    {
        return static::canEdit($record);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user instanceof User) {
            $query = app(AttendanceService::class)->restrictTemplateQuery($query, $user, forManagement: true);
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        $user = auth()->user();
        $service = app(AttendanceService::class);
        $locations = $user instanceof User ? $service->locationsFor($user) : [];
        if ($user instanceof User) {
            $locations = array_filter($locations, fn ($name, $id) => AttendanceService::canManageAt($user, (int) $id)
                || $service->groupOptions($user, (int) $id) !== [], ARRAY_FILTER_USE_BOTH);
        }

        return $schema->schema([
            Forms\Components\TextInput::make('name')->label('Name')->required()->maxLength(150),
            Forms\Components\TextInput::make('title')->label('Titel der Liste')->helperText('Leer = Name der Vorlage')->maxLength(150),
            Forms\Components\Select::make('kind')->label('Art')->options(config('anwesenheit.kinds'))->required(),
            Forms\Components\TextInput::make('duration_minutes')->label('Übliche Dauer (Minuten)')->numeric()->minValue(1)->maxValue(AttendanceService::MAX_MINUTES),
            Forms\Components\Select::make('ext_location_id')->label('Standort')->options($locations)->live()
                ->afterStateUpdated(function (Set $set): void {
                    $set('group_id', null);
                    $set('leader_id', null);
                })
                ->default(array_key_first($locations))->required(AttendanceService::locationsOn())->visible(AttendanceService::locationsOn()),
            Forms\Components\Select::make('group_id')->label('Abteilung')
                ->options(fn (Get $get) => $user instanceof User ? $service->groupOptions($user, (int) $get('ext_location_id') ?: null) : [])
                ->default($user instanceof User ? $service->defaultGroup($user, array_key_first($locations) ?: null) : null)
                ->required(AttendanceService::locationsOn())->visible(AttendanceService::locationsOn())->live()
                ->afterStateUpdated(fn (Set $set) => $set('leader_id', null)),
            Forms\Components\Select::make('leader_id')->label('Übliche Leitung')
                ->options(fn (Get $get) => $user instanceof User
                    ? $service->members((int) $get('ext_location_id') ?: null, (int) $get('group_id') ?: null)->pluck('name', 'id')
                    : [])
                ->searchable(),
            Forms\Components\Textarea::make('recipients')->label('E-Mail-Empfänger')->rows(2)
                ->helperText('Leer = Standard-Empfänger aus den Einstellungen')
                ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                    $all = preg_split('/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    if (count($all) !== count(AttendanceList::parseRecipients((string) $value))) {
                        $fail('Mindestens eine E-Mail-Adresse ist ungültig.');
                    }
                }),
            Forms\Components\Toggle::make('is_active')->label('Aktiv')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('group.display_name')->label('Abteilung')->toggleable(),
                Tables\Columns\TextColumn::make('kind')->label('Art')->formatStateUsing(fn ($state) => config('anwesenheit.kinds')[$state] ?? $state),
                Tables\Columns\TextColumn::make('duration_minutes')->label('Dauer')->suffix(' Min.'),
                Tables\Columns\IconColumn::make('is_active')->label('Aktiv')->boolean(),
            ])
            ->defaultSort('name')
            ->recordActions([Actions\EditAction::make(), Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTemplates::route('/'),
            'create' => Pages\CreateTemplate::route('/neu'),
            'edit' => Pages\EditTemplate::route('/{record}/bearbeiten'),
        ];
    }
}

<?php

namespace Modules\Atemschutz\src\Filament\Resources;

use App\Models\Group;
use App\Models\User;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Atemschutz\src\Filament\Resources\WearerResource\Pages;
use Modules\Atemschutz\src\Filament\Resources\WearerResource\RelationManagers\TrainingsRelationManager;
use Modules\Atemschutz\src\Models\Training;
use Modules\Atemschutz\src\Models\Wearer;
use Modules\Atemschutz\src\Services\MonitorService;
use Modules\Atemschutz\src\Services\ReadinessService;

/** /admin/atemschutz/geraetetraeger – the pool: Atemschutzwart (all) and Wehrführung per Standort (their own Kameraden) */
class WearerResource extends Resource
{
    protected static ?string $model = Wearer::class;

    protected static ?string $slug = 'atemschutz/geraetetraeger';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Geräteträger';

    protected static ?string $modelLabel = 'Geräteträger';

    protected static ?string $pluralModelLabel = 'Geräteträger';

    protected static string|\UnitEnum|null $navigationGroup = 'Atemschutz';

    protected static ?int $navigationSort = 10;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_active && MonitorService::canCare($user);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('user.groups');
        $user = auth()->user();
        $locations = $user instanceof User ? MonitorService::careLocationIds($user) : [];

        return $locations === null ? $query : $query->whereHas('user', fn ($q) => $q->whereIn('ext_location_id', $locations));
    }

    public static function canEdit($record): bool
    {
        return MonitorService::canCareFor(auth()->user(), $record->user_id);
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    /** readiness of the given Geräteträger, keyed by user id */
    public static function states(Collection $wearers): Collection
    {
        return app(ReadinessService::class)->forWearers($wearers);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Kamerad')->schema([
                Forms\Components\Select::make('user_id')->label('Kamerad')
                    ->options(function () {
                        $locations = MonitorService::careLocationIds(auth()->user());

                        return User::active()
                            ->whereNotIn('id', Wearer::where('is_active', true)->pluck('user_id'))
                            ->when($locations !== null, fn ($q) => $q->whereIn('ext_location_id', $locations))
                            ->orderBy('name')->pluck('name', 'id')->all();
                    })
                    ->searchable()->required()
                    ->helperText('Wer schon im Pool steht, wird nicht angeboten. Ein früherer Geräteträger wird wieder aufgenommen.'),
            ])->visibleOn('create'),
            Section::make('Qualifikation und Tauglichkeit')
                ->description('Nur Daten, keine Befunde.')
                ->schema([
                    Forms\Components\Toggle::make('is_agt')->label('AGT-Lehrgang absolviert'),
                    Forms\Components\DatePicker::make('agt_qualified_on')->label('AGT-Lehrgang am (optional)')->maxDate(now()),
                    Forms\Components\DatePicker::make('g263_examined_on')->label('G26.3 untersucht am')->maxDate(now())->live()
                        ->afterStateUpdated(function ($state, $set, $get) {
                            // Usually valid for three years (under 50): a suggestion only
                            if ($state && ! $get('g263_valid_until')) {
                                $set('g263_valid_until', Carbon::parse($state)->addYears(3)->toDateString());
                            }
                        }),
                    Forms\Components\DatePicker::make('g263_valid_until')->label('G26.3 gültig bis')->afterOrEqual('g263_examined_on'),
                    Forms\Components\Toggle::make('is_csa')->label('CSA-Träger (Chemikalienschutzanzug)'),
                    Forms\Components\DatePicker::make('csa_qualified_on')->label('CSA-Ausbildung am (optional)')->maxDate(now()),
                ])->columns(['default' => 1, 'md' => 2]),
            Section::make('Chip und Notiz')->schema([
                Forms\Components\TextInput::make('rfid_uid')->label('RFID-Chip')->maxLength(100)->autocomplete(false)
                    ->unique(ignoreRecord: true)
                    ->validationMessages(['unique' => 'Dieser Chip gehört schon einem anderen Kameraden.'])
                    ->helperText('Feld anklicken und den Chip an den Leser halten.'),
                Forms\Components\TextInput::make('notes')->label('Notiz (keine Befunde!)')->maxLength(500),
                Forms\Components\Toggle::make('is_active')->label('Im Atemschutz-Pool')->default(true),
            ])->columns(['default' => 1, 'md' => 2]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('Name')->searchable()->sortable()
                    ->description(fn (Wearer $record) => collect([$record->user->rank, $record->user->groups->pluck('display_name')->implode(', ')])->filter()->implode(' · ') ?: null),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (Wearer $record, $livewire) => ($s = $livewire->state($record)) ? ($s['ready'] ? ($s['soon'] ? 'läuft ab' : 'bereit') : 'nicht bereit') : '–')
                    ->color(fn (string $state) => match ($state) {
                        'bereit' => 'success', 'läuft ab' => 'warning', 'nicht bereit' => 'danger', default => 'gray'
                    }),
                ...collect(ReadinessService::ITEMS)->map(fn (string $label, string $key) => Tables\Columns\TextColumn::make('item_'.$key)->label($label)
                    ->state(function (Wearer $record, $livewire) use ($key) {
                        $item = $livewire->state($record)['items'][$key] ?? null;
                        if (! $item) {
                            return '–';
                        }

                        return $key === 'agt' ? ($item['ok'] ? 'ja' : 'nein') : ($item['until'] ? 'bis '.$item['until']->format('d.m.Y') : 'fehlt');
                    })
                    ->color(function (Wearer $record, $livewire) use ($key) {
                        $item = $livewire->state($record)['items'][$key] ?? null;

                        return ! $item ? 'gray' : ($item['ok'] ? ($item['until'] && $item['until']->lte(now()->addDays(ReadinessService::SOON_DAYS)) ? 'warning' : null) : (in_array($key, ReadinessService::BLOCKING, true) ? 'danger' : 'gray'));
                    }))->values()->all(),
                Tables\Columns\TextColumn::make('csa')->label('CSA')
                    ->state(fn (Wearer $record, $livewire) => ($s = $livewire->state($record)['csa'] ?? null) ? ($s['ready'] ? 'bereit' : 'nicht bereit') : '–')
                    ->color(fn (string $state) => match ($state) {
                        'bereit' => 'success', 'nicht bereit' => 'danger', default => 'gray'
                    }),
                Tables\Columns\IconColumn::make('rfid_uid')->label('Chip')->boolean()->state(fn (Wearer $record) => filled($record->rfid_uid)),
            ])
            ->defaultSort('user.name')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Im Pool')->default(true)
                    ->trueLabel('Im Pool')->falseLabel('Nicht mehr im Pool')->placeholder('Alle'),
                Tables\Filters\SelectFilter::make('status')->label('Einsatzbereitschaft')
                    ->options(['ready' => 'Einsatzbereit', 'soon' => 'Frist läuft ab', 'not_ready' => 'Nicht einsatzbereit'])
                    ->query(function (Builder $query, array $data) {
                        if (! $data['value']) {
                            return $query;
                        }
                        $states = static::states(static::getEloquentQuery()->get());

                        return $query->whereKey($states->filter(fn ($s) => match ($data['value']) {
                            'ready' => $s['ready'] && ! $s['soon'],
                            'soon' => $s['ready'] && $s['soon'],
                            default => ! $s['ready'],
                        })->pluck('wearer.id')->all());
                    }),
                Tables\Filters\TernaryFilter::make('is_csa')->label('CSA-Träger'),
                Tables\Filters\SelectFilter::make('group')->label('Abteilung')
                    ->options(fn () => Group::query()->orderBy('sort_order')->orderBy('name')->get()->mapWithKeys(fn (Group $g) => [$g->id => $g->getDisplayNameAttribute()])->all())
                    ->query(fn (Builder $query, array $data) => $data['value'] ? $query->whereHas('user.groups', fn ($q) => $q->whereKey((int) $data['value'])) : $query),
            ])
            ->actions([Actions\EditAction::make()])
            ->bulkActions([
                Actions\BulkAction::make('training')
                    ->label('Nachweis eintragen')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->modalHeading('Unterweisung, Belastungsübung oder Übung eintragen')
                    ->deselectRecordsAfterCompletion()
                    ->schema([
                        Forms\Components\Select::make('kind')->label('Art')->options(Training::KINDS)->required()->default('belastungsuebung'),
                        Forms\Components\DatePicker::make('done_on')->label('Datum')->required()->default(now('Europe/Berlin'))->maxDate(now('Europe/Berlin')),
                        Forms\Components\TextInput::make('minutes')->label('Tragezeit (min)')->integer()->minValue(1)->maxValue(600),
                        Forms\Components\Toggle::make('csa')->label('mit CSA'),
                        Forms\Components\TextInput::make('notes')->label('Notiz')->maxLength(300),
                    ])
                    ->action(function (Collection $records, array $data) {
                        try {
                            $count = app(MonitorService::class)->recordTraining(auth()->user(), $records->pluck('user_id')->all(), $data['kind'], Carbon::parse($data['done_on'])->toDateString(),
                                $data['notes'] ?? null, filled($data['minutes'] ?? null) ? (int) $data['minutes'] : null, (bool) ($data['csa'] ?? false));
                        } catch (ValidationException $e) {
                            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

                            return;
                        }
                        Notification::make()->title(Training::KINDS[$data['kind']].' für '.$count.' Geräteträger eingetragen.')->success()->send();
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [TrainingsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWearers::route('/'),
            'create' => Pages\CreateWearer::route('/neu'),
            'edit' => Pages\EditWearer::route('/{record}/bearbeiten'),
        ];
    }
}

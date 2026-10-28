<?php

namespace Extensions\Locations\Filament;

use App\Models\Group;
use App\Models\User;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Models\StandardRoleAssignment;
use Extensions\Locations\Services\StandardRoleAssignments;
use Extensions\Locations\Services\StandardRoleCatalog;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * @property-read Schema $form
 */
class StandardRolesPage extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $slug = 'standardrollen';

    protected static ?string $navigationLabel = 'Standardrollen';

    protected static ?string $title = 'Standardrollen verwalten';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?int $navigationSort = 8;

    protected string $view = 'locations::filament.standard-roles';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_active && $user->is_admin;
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        $catalog = app(StandardRoleCatalog::class);
        $profiles = collect($catalog->all())
            ->filter(fn (array $definition, string $key) => $catalog->isAssignable($key))
            ->mapWithKeys(fn (array $definition, string $key) => [$key => $definition['name']])->all();
        $notReady = collect($catalog->all())
            ->reject(fn (array $definition, string $key) => $catalog->isAssignable($key))
            ->pluck('name')->implode(', ');

        return $schema
            ->statePath('data')
            ->schema([
                Section::make('Rolle zuweisen')
                    ->description('Profilzuweisungen sind befristbar und werden mit Grund protokolliert. Die verfügbaren Profile gewähren nur die bereits angebundenen Rechte: GBI/SBI standortübergreifend, Wehrführung für Anwesenheit und Übungsdienste, Jugend- und Kinderwarte für zugewiesene Abteilungen; Gemeindewarte können alle passenden Abteilungen standortübergreifend verwalten. Der Bürgermeister erhält bei aktivierter Führungsübersicht ausschließlich jährliche Einsatzzahlen über alle Standorte, aber keinen Zugang zu den Einsatzberichten oder anderen Modulen. Noch nicht freigegeben: '.$notReady.'.')
                    ->columns(2)
                    ->schema([
                        Select::make('user_id')
                            ->label('Kamerad')
                            ->options(fn () => User::withoutGlobalScopes()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()->required(),
                        Select::make('profile')
                            ->label('Standardrolle')
                            ->options($profiles)->live()->required(),
                        Select::make('location_id')
                            ->label('Standort')
                            ->options(fn () => Location::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                            ->visible(fn (Get $get) => $catalog->has((string) $get('profile'))
                                && in_array($catalog->get((string) $get('profile'))['scope'], ['location', 'group'], true))
                            ->required(fn (Get $get) => $catalog->has((string) $get('profile'))
                                && in_array($catalog->get((string) $get('profile'))['scope'], ['location', 'group'], true))
                            ->live()->searchable(),
                        Select::make('group_id')
                            ->label('Abteilung')
                            ->helperText(fn (Get $get) => $catalog->has((string) $get('profile'))
                                && $catalog->get((string) $get('profile'))['scope'] === 'global'
                                ? 'Leer lassen, um alle passenden Abteilungen standortübergreifend zu verwalten.'
                                : null)
                            ->options(function (Get $get) use ($catalog): array {
                                $profile = (string) $get('profile');
                                if (! $catalog->has($profile) || $catalog->get($profile)['group'] === null) {
                                    return [];
                                }

                                return Group::query()->withoutGlobalScopes()
                                    ->when($get('location_id'), fn (Builder $query, $locationId) => $query->where('ext_location_id', $locationId))
                                    ->whereRaw('lower(name) = ?', [$catalog->get($profile)['group']])
                                    ->orderBy('name')->get()->mapWithKeys(fn (Group $group) => [$group->id => $group->displayName()])->all();
                            })
                            ->visible(fn (Get $get) => $catalog->has((string) $get('profile'))
                                && $catalog->get((string) $get('profile'))['group'] !== null)
                            ->required(fn (Get $get) => $catalog->has((string) $get('profile'))
                                && $catalog->get((string) $get('profile'))['group'] !== null
                                && $catalog->get((string) $get('profile'))['scope'] !== 'global')
                            ->searchable(),
                        Textarea::make('reason')->label('Begründung')->required()->maxLength(500)->columnSpanFull(),
                        DateTimePicker::make('expires_at')->label('Gültig bis (optional)')->native(false),
                    ]),
            ]);
    }

    public function assign(): void
    {
        $data = $this->form->getState();
        try {
            app(StandardRoleAssignments::class)->grant(
                auth()->user(),
                (int) $data['user_id'],
                (string) $data['profile'],
                filled($data['location_id'] ?? null) ? (int) $data['location_id'] : null,
                filled($data['group_id'] ?? null) ? (int) $data['group_id'] : null,
                (string) $data['reason'],
                filled($data['expires_at'] ?? null) ? Carbon::parse($data['expires_at'])->toISOString() : null,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError("data.{$field}", implode(' ', $messages));
            }

            return;
        }

        $this->form->fill();
        $this->resetValidation();
        Notification::make()->title('Standardrolle zugewiesen')->success()->send();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(StandardRoleAssignment::query()->with(['member', 'location', 'group', 'grantedBy']))
            ->columns([
                TextColumn::make('member.name')->label('Kamerad')->searchable()->sortable(),
                TextColumn::make('profile')->label('Rolle')
                    ->formatStateUsing(fn (string $state) => app(StandardRoleCatalog::class)->get($state)['name']),
                TextColumn::make('scope')->label('Geltungsbereich')
                    ->state(function (StandardRoleAssignment $record): string {
                        $parts = collect([$record->location?->name, $record->group?->displayName()]);
                        if ($record->location === null && $record->group === null) {
                            $profile = app(StandardRoleCatalog::class)->get($record->profile);
                            $parts->push($profile['group'] === null ? 'Alle Standorte' : 'Alle '.match ($profile['group']) {
                                'jugendfeuerwehr' => 'Jugendfeuerwehr-Abteilungen',
                                'kinderfeuerwehr' => 'Kinderfeuerwehr-Abteilungen',
                                default => 'passenden Abteilungen',
                            });
                        }

                        return $parts->filter()->implode(' · ');
                    }),
                TextColumn::make('reason')->label('Begründung')->wrap(),
                TextColumn::make('grantedBy.name')->label('Zugewiesen von'),
                TextColumn::make('granted_at')->label('Zugewiesen am')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('expires_at')->label('Gültig bis')->dateTime('d.m.Y H:i')->placeholder('Unbefristet')->sortable(),
                TextColumn::make('revoked_at')->label('Widerrufen am')->dateTime('d.m.Y H:i')->placeholder('Aktiv')->sortable(),
            ])
            ->defaultSort('granted_at', 'desc')
            ->recordActions([
                Action::make('revoke')
                    ->label('Widerrufen')
                    ->color('danger')
                    ->icon('heroicon-o-no-symbol')
                    ->visible(fn (StandardRoleAssignment $record) => $record->revoked_at === null)
                    ->schema([TextInput::make('reason')->label('Widerrufsgrund')->required()->maxLength(500)])
                    ->requiresConfirmation()
                    ->action(function (StandardRoleAssignment $record, array $data): void {
                        app(StandardRoleAssignments::class)->revoke(auth()->user(), (int) $record->id, $data['reason']);
                        Notification::make()->title('Standardrolle widerrufen')->success()->send();
                    }),
            ]);
    }
}

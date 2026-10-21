<?php

namespace Extensions\Locations\Filament\Resources\LocationResource\RelationManagers;

use App\Models\User;
use App\Services\ModuleRegistry;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Models\LocationModuleRole;
use Extensions\Locations\Services\LocationModuleRoles;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ModuleRolesRelationManager extends RelationManager
{
    protected static string $relationship = 'moduleRoles';

    protected static ?string $title = 'Standort-Modulrollen und Verlauf';

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageName): bool
    {
        $user = auth()->user();

        return $ownerRecord instanceof Location && $user instanceof User && app(LocationModuleRoles::class)->isGlobalAdmin($user);
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Nur globale Admins vergeben Rollen für den vorbereiteten Adapter Anwesenheit, auch vorab. Wirksam erst mit LOCATIONS_SCOPED_ROLE_MODULES=anwesenheit. Ohne Rolle: nur Moduleinstieg, keine Objektberechtigung. Alte Modulverwaltung ändert nur Legacy-Zugänge. Abgelaufene Zuweisungen vor Ersatz ausdrücklich entziehen.')
            ->columns([
                Tables\Columns\TextColumn::make('member.name')->label('Mitglied'),
                Tables\Columns\TextColumn::make('module')->label('Modul'),
                Tables\Columns\TextColumn::make('role')->label('Rolle')->placeholder('Nur Zugang'),
                Tables\Columns\TextColumn::make('granted_at')->label('Vergeben')->dateTime()->timezone('Europe/Berlin'),
                Tables\Columns\TextColumn::make('granted_by')->label('Admin-ID'),
                Tables\Columns\TextColumn::make('reason')->label('Begründung')->wrap(),
                Tables\Columns\TextColumn::make('expires_at')->label('Ablauf')->dateTime()->timezone('Europe/Berlin'),
                Tables\Columns\TextColumn::make('revoked_at')->label('Entzogen')->dateTime()->timezone('Europe/Berlin'),
                Tables\Columns\TextColumn::make('revoked_by')->label('Entzogen durch ID'),
                Tables\Columns\TextColumn::make('revoked_source')->label('Entzugsquelle')
                    ->formatStateUsing(fn ($state) => $state === 'membership' ? 'Automatisch: Mitgliedschaft beendet' : 'Globaler Admin'),
                Tables\Columns\TextColumn::make('revoke_reason')->label('Entzugsgrund')->wrap(),
            ])->defaultSort('id', 'desc')
            ->headerActions([
                Action::make('grant')->label('Zuweisung vergeben')
                    ->schema([
                        Select::make('user_id')->label('Mitglied')->required()->searchable()
                            ->options(fn () => User::withoutGlobalScopes()->where('is_active', true)
                                ->where(fn ($q) => $q->where('ext_location_id', $this->getOwnerRecord()->getKey())
                                    ->orWhereIn('id', DB::table('x_location_user')
                                        ->where('location_id', $this->getOwnerRecord()->getKey())->select('user_id')))
                                ->orderBy('name')->pluck('name', 'id')),
                        Select::make('module')->label('Modul')->required()->live()
                            ->options(fn () => collect(app(ModuleRegistry::class)->all())->only(LocationModuleRoles::SUPPORTED_MODULES)
                                ->mapWithKeys(fn ($def, $key) => [$key => $def['name']])),
                        Select::make('role')->label('Rolle')->placeholder('Nur Zugang')
                            ->options(fn (Get $get) => collect(app(ModuleRegistry::class)->get((string) $get('module'))['roles'] ?? [])->mapWithKeys(fn ($role) => [$role => $role])),
                        Textarea::make('reason')->label('Begründung')->required()->maxLength(500),
                        DateTimePicker::make('expires_at')->label('Gültig bis (optional)')->timezone('Europe/Berlin'),
                    ])->action(function (array $data): void {
                        app(LocationModuleRoles::class)->grant(auth()->user(), (int) $this->getOwnerRecord()->getKey(),
                            (int) $data['user_id'], $data['module'], $data['role'] ?? null, $data['reason'], $data['expires_at'] ?? null);
                    }),
            ])->recordActions([
                Action::make('revoke')->label('Entziehen')->requiresConfirmation()
                    ->visible(fn (LocationModuleRole $record) => $record->getAttribute('revoked_at') === null)
                    ->schema([Textarea::make('reason')->label('Begründung')->required()->maxLength(500)])
                    ->action(function (LocationModuleRole $record, array $data): void {
                        app(LocationModuleRoles::class)->revoke(auth()->user(), (int) $record->getKey(),
                            (int) $this->getOwnerRecord()->getKey(), $data['reason']);
                    }),
            ]);
    }
}

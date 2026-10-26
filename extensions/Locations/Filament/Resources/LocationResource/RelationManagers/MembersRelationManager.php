<?php

namespace Extensions\Locations\Filament\Resources\LocationResource\RelationManagers;

use App\Models\Group;
use App\Models\User;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\LocationAccess;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'Mitglieder und Standort-Admins';

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageName): bool
    {
        $user = auth()->user();

        return $user && $ownerRecord instanceof Location && app(LocationAccess::class)->canManage($user, $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Name')->searchable(),
                Tables\Columns\TextColumn::make('email')->label('E-Mail'),
                Tables\Columns\TextColumn::make('groups.display_name')->label('Abteilungen'),
                Tables\Columns\TextColumn::make('pivot.role')->label('Rolle')->badge()
                    ->formatStateUsing(fn ($state) => $state === 'admin' ? 'Standort-Admin' : 'Mitglied'),
            ])
            ->headerActions([
                Actions\AttachAction::make()
                    ->label('Person hinzufügen')
                    ->preloadRecordSelect()
                    ->schema(fn (Actions\AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Forms\Components\Select::make('role')->label('Rolle')->options(['member' => 'Mitglied', 'admin' => 'Standort-Admin'])->default('member')->required(),
                    ]),
            ])
            ->recordActions([
                Actions\Action::make('toggleAdmin')
                    ->label(fn (User $record) => $record->pivot->role === 'admin' ? 'Zum Mitglied machen' : 'Zum Standort-Admin machen')
                    ->icon('heroicon-o-shield-check')
                    ->action(function (User $record) {
                        $this->getOwnerRecord()->members()->updateExistingPivot($record->getKey(), ['role' => $record->pivot->role === 'admin' ? 'member' : 'admin']);
                        app(LocationAccess::class)->forget();
                    }),
                Actions\Action::make('assignGroups')
                    ->label('Abteilungen zuordnen')
                    ->icon('heroicon-o-user-group')
                    ->form([
                        Forms\Components\Select::make('groups')
                            ->label('Abteilungen')
                            ->multiple()
                            ->searchable()
                            ->options(fn () => Group::options(fn ($query) => $query->where('ext_location_id', $this->getOwnerRecord()->getKey())))
                            ->helperText('Es werden nur Abteilungen dieses Standorts geändert.'),
                    ])
                    ->fillForm(fn (User $record): array => [
                        'groups' => $record->groups()
                            ->where('groups.ext_location_id', $this->getOwnerRecord()->getKey())
                            ->pluck('groups.id')
                            ->all(),
                    ])
                    ->action(function (User $record, array $data): void {
                        $locationGroupIds = $this->getOwnerRecord()->groups()
                            ->pluck('groups.id')
                            ->map(fn ($id) => (int) $id);
                        $selectedGroupIds = collect($data['groups'] ?? [])
                            ->map(fn ($id) => (int) $id)
                            ->unique()
                            ->values();

                        if ($selectedGroupIds->diff($locationGroupIds)->isNotEmpty()) {
                            throw ValidationException::withMessages([
                                'groups' => 'Bitte nur Abteilungen dieses Standorts wählen.',
                            ]);
                        }

                        $record->groups()->detach($locationGroupIds->diff($selectedGroupIds)->all());
                        $record->groups()->syncWithoutDetaching($selectedGroupIds->all());
                    }),
                Actions\DetachAction::make()->after(fn () => app(LocationAccess::class)->forget()),
            ]);
    }
}

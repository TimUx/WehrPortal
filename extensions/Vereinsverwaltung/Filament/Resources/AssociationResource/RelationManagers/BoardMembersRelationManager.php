<?php

namespace Extensions\Vereinsverwaltung\Filament\Resources\AssociationResource\RelationManagers;

use App\Models\User;
use Extensions\Vereinsverwaltung\Models\Association;
use Extensions\Vereinsverwaltung\Services\AssociationAccess;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BoardMembersRelationManager extends RelationManager
{
    protected static string $relationship = 'boardMembers';

    protected static ?string $title = 'Zugewiesene Vorstandsmitglieder';

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageName): bool
    {
        return auth()->user()?->is_admin && $ownerRecord instanceof Association;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Name')->searchable(),
                Tables\Columns\TextColumn::make('email')->label('E-Mail'),
            ])
            ->headerActions([
                Actions\Action::make('assignBoardMember')
                    ->label('Vorstandsmitglied zuweisen')
                    ->form([
                        Forms\Components\Select::make('user_id')
                            ->label('Aktives Benutzerkonto')
                            ->options(fn () => User::query()->withoutGlobalScopes()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $user = User::query()->withoutGlobalScopes()->where('is_active', true)->findOrFail($data['user_id']);
                        $this->getOwnerRecord()->boardMembers()->syncWithoutDetaching([$user->id]);
                        app(AssociationAccess::class)->syncModuleAccess($user);
                    }),
            ])
            ->recordActions([
                Actions\Action::make('removeBoardMember')
                    ->label('Zuweisung entfernen')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (User $record): void {
                        $association = $this->getOwnerRecord();
                        $association->boardMembers()->detach($record->id);
                        app(AssociationAccess::class)->syncModuleAccess($record);
                    }),
            ]);
    }
}

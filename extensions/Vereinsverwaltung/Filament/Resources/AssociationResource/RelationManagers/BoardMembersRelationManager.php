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
                Tables\Columns\TextColumn::make('office')->label('Amt')
                    ->state(fn (User $record) => Association::officeLabel($record->getAttribute('office'), (bool) $record->getAttribute('is_deputy'))),
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
                        ...$this->officeFields(),
                    ])
                    ->action(fn (array $data) => app(AssociationAccess::class)->setBoardMember(
                        auth()->user(), $this->association(), (int) $data['user_id'], $data['office'] ?? null, (bool) ($data['is_deputy'] ?? false),
                    )),
            ])
            ->recordActions([
                Actions\Action::make('setOffice')
                    ->label('Amt ändern')
                    ->fillForm(fn (User $record) => ['office' => $record->getAttribute('office'), 'is_deputy' => (bool) $record->getAttribute('is_deputy')])
                    ->form($this->officeFields())
                    ->action(fn (User $record, array $data) => app(AssociationAccess::class)->setBoardMember(
                        auth()->user(), $this->association(), $record->id, $data['office'] ?? null, (bool) ($data['is_deputy'] ?? false),
                    )),
                Actions\Action::make('removeBoardMember')
                    ->label('Zuweisung entfernen')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (User $record) => app(AssociationAccess::class)->removeBoardMember(auth()->user(), $this->association(), $record->id)),
            ]);
    }

    private function association(): Association
    {
        /** @var Association $association */
        $association = $this->getOwnerRecord();

        return $association;
    }

    /** @return list<Forms\Components\Field> */
    private function officeFields(): array
    {
        return [
            Forms\Components\Select::make('office')->label('Amt')->options(Association::OFFICES)->placeholder('Vorstandsmitglied ohne Amt')
                ->helperText('Der Vorsitz und seine Stellvertretung stellen den Vorstand ihres Vereins selbst zusammen.'),
            Forms\Components\Toggle::make('is_deputy')->label('Stellvertretung'),
        ];
    }
}

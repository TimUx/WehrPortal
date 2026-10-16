<?php

namespace Extensions\Locations\Filament\Resources\LocationResource\RelationManagers;

use App\Models\User;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\LocationAccess;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

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
                Actions\DetachAction::make()->after(fn () => app(LocationAccess::class)->forget()),
            ]);
    }
}

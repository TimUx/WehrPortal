<?php

namespace Modules\Kleiderkammer\src\Filament\Resources;

use App\Enums\MemberStatus;
use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Kleiderkammer\src\Filament\Concerns\KleiderkammerArea;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource\Pages;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource\RelationManagers;
use Modules\Kleiderkammer\src\Services\IssuingService;
use Modules\Kleiderkammer\src\Services\PersonalAccess;

// The suite's users seen from the Kleiderkammer. Kleiderwarte edit only the Kleiderkammer profile
// (notes, clothing sizes, equipment); Abteilungen, functions, rank and status stay with the admins (/admin/kameraden).
class MemberResource extends Resource
{
    use KleiderkammerArea;

    protected static ?string $model = User::class;

    protected static ?string $slug = 'kleiderkammer/kameraden';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Mitglieder';

    protected static ?string $modelLabel = 'Mitglied';

    protected static ?string $pluralModelLabel = 'Mitglieder';

    protected static ?int $navigationSort = 10;

    public static function canCreate(): bool
    {
        return false;
    }

    /** @return Builder<User> */
    public static function getEloquentQuery(): Builder
    {
        return app(PersonalAccess::class)->members(auth()->user())
            ->when(static::minimalProfile(), fn (Builder $query) => $query->select(['users.id', 'users.name', 'users.status']));
    }

    public static function minimalProfile(): bool
    {
        return app(PersonalAccess::class)->usesMinimalMemberProfile(auth()->user());
    }

    public static function canEdit(Model $record): bool
    {
        return static::canAccess() && app(PersonalAccess::class)->members(auth()->user())->whereKey($record->getKey())->exists();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Mitglied')
                ->description(fn () => static::minimalProfile()
                    ? 'Konfektionsgrößen gelten für die Person an allen Standorten. Ausgaben werden getrennt nach berechtigter Herkunft angezeigt. Allgemeine Mitgliedsnotizen bleiben globalen Admins vorbehalten.'
                    : 'Abteilungen, Funktionen, Dienstgrad und Status pflegen Admins in der Kameradenverwaltung.')
                ->schema(array_filter([
                    TextEntry::make('name')->label('Name'),
                    static::minimalProfile() ? null : TextEntry::make('groups_list')->label('Abteilungen')->state(fn (User $record) => $record->groups->pluck('name')->implode(', '))->placeholder('–'),
                    static::minimalProfile() ? null : TextEntry::make('functions_list')->label('Funktionen')->state(fn (User $record) => $record->functions->map->fullLabel()->implode(', '))->placeholder('–'),
                    static::minimalProfile() ? null : TextEntry::make('rank')->label('Dienstgrad')->placeholder('–'),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (MemberStatus $state) => $state->label())
                        ->color(fn (MemberStatus $state) => match ($state) {
                            MemberStatus::Active => 'success',
                            MemberStatus::Inactive => 'warning',
                            MemberStatus::Left => 'danger',
                        }),
                    static::minimalProfile() ? null : Forms\Components\Textarea::make('notes')
                        ->label('Notizen')
                        ->rows(3)
                        ->columnSpanFull(),
                ]))
                ->columns(['default' => 1, 'md' => 4])
                ->columnSpanFull(),
        ]);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $options = fn (string $enum) => collect($enum::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('kleiderkammerItems')->when(! static::minimalProfile(), fn ($q) => $q->with('groups')))
            ->columns(array_filter([
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                static::minimalProfile() ? null : Tables\Columns\TextColumn::make('groups.name')
                    ->label('Abteilungen')
                    ->badge()
                    ->placeholder('–'),

                static::minimalProfile() ? null : Tables\Columns\TextColumn::make('rank')
                    ->label('Dienstgrad')
                    ->placeholder('–')
                    ->searchable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (MemberStatus $state) => $state->label())
                    ->color(fn (MemberStatus $state) => match ($state) {
                        MemberStatus::Active => 'success',
                        MemberStatus::Inactive => 'warning',
                        MemberStatus::Left => 'danger',
                    }),

                Tables\Columns\TextColumn::make('kleiderkammer_items_count')
                    ->label('Ausgeliehen')
                    ->sortable(),

                static::minimalProfile() ? null : Tables\Columns\TextColumn::make('notes')
                    ->label('Notizen')
                    ->limit(40)
                    ->placeholder('–')
                    ->toggleable(),
            ]))
            ->defaultSort('name')
            ->filters(array_filter([
                static::minimalProfile() ? null : Tables\Filters\SelectFilter::make('groups')
                    ->label('Abteilung')
                    ->relationship('groups', 'name', fn ($query) => $query->orderBy('sort_order')->orderBy('name'))
                    ->multiple()
                    ->preload(),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options($options(MemberStatus::class)),

                Tables\Filters\Filter::make('has_items')
                    ->label('Mit Ausrüstung')
                    ->query(fn (Builder $query) => $query->has('kleiderkammerItems')),
            ]))
            ->recordUrl(fn (User $record) => static::getUrl('edit', ['record' => $record]))
            ->recordActions([
                Actions\EditAction::make()->label('Profil'),
                Actions\Action::make('takeBack')
                    ->label('Alle zurückfordern')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->requiresConfirmation()
                    ->modalHeading('Alle Teile zurückfordern?')
                    ->modalDescription('Alle offenen Leihvorgänge dieses Mitglieds werden als zurückgegeben markiert.')
                    ->visible(fn (User $record) => $record->kleiderkammer_items_count > 0)
                    ->action(function (User $record) {
                        app(IssuingService::class)->takeBack($record);
                        Notification::make()->title('Alle Teile zurückgefordert.')->success()->send();
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\IssuancesRelationManager::class,
            RelationManagers\SizesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMembers::route('/'),
            'edit' => Pages\EditMember::route('/{record}/bearbeiten'),
        ];
    }
}

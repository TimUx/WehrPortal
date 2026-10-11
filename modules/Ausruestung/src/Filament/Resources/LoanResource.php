<?php

namespace Modules\Ausruestung\src\Filament\Resources;

use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Ausruestung\src\Enums\EquipmentStatus;
use Modules\Ausruestung\src\Enums\LoanStatus;
use Modules\Ausruestung\src\Filament\Resources\LoanResource\Pages;
use Modules\Ausruestung\src\Filament\Support\EquipmentActions;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Ausruestung\src\Models\Loan;
use Modules\Ausruestung\src\Services\LoanService;

/** Who has which item until when, and the reservations */
class LoanResource extends Resource
{
    protected static ?string $model = Loan::class;

    protected static ?string $slug = 'ausleihe';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationLabel = 'Ausleihe';

    protected static ?string $modelLabel = 'Ausleihe';

    protected static ?string $pluralModelLabel = 'Ausleihen';

    protected static ?int $navigationSort = 20;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $overdue = Loan::where('status', LoanStatus::Active->value)->where('due_at', '<', now())->count();

        return $overdue > 0 ? (string) $overdue : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['equipment', 'user']))
            ->columns([
                Tables\Columns\TextColumn::make('equipment.name')->label('Gerät')
                    ->description(fn (Loan $record) => $record->equipment->code)
                    ->searchable(['name', 'code']),
                Tables\Columns\TextColumn::make('user.name')->label('Bei')->searchable(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (Loan $record) => $record->isOverdue() ? 'Überfällig' : $record->status->label())
                    ->color(fn (Loan $record) => $record->isOverdue() ? 'danger' : $record->status->color()),
                Tables\Columns\TextColumn::make('starts_at')->label('Von')->dateTime('d.m.Y H:i')->timezone('Europe/Berlin')->sortable(),
                Tables\Columns\TextColumn::make('due_at')->label('Bis')->dateTime('d.m.Y H:i')->timezone('Europe/Berlin')->sortable(),
                Tables\Columns\TextColumn::make('returned_at')->label('Zurück')->dateTime('d.m.Y H:i')->timezone('Europe/Berlin')->placeholder('–')->toggleable(),
                Tables\Columns\TextColumn::make('notes')->label('Notiz')->limit(40)->placeholder('–')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('due_at')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Status')->options(LoanStatus::options())->multiple()
                    ->default([LoanStatus::Reserved->value, LoanStatus::Active->value]),
                Tables\Filters\Filter::make('overdue')->label('Überfällig')
                    ->query(fn (Builder $query) => $query->where('status', LoanStatus::Active->value)->where('due_at', '<', now())),
            ])
            ->recordActions([
                Actions\Action::make('handOut')
                    ->label('Ausgeben')
                    ->icon('heroicon-o-arrow-right-circle')
                    ->visible(fn (Loan $record) => $record->status === LoanStatus::Reserved)
                    ->requiresConfirmation()
                    ->action(function (Loan $record, Actions\Action $action) {
                        EquipmentActions::attempt($action, fn () => app(LoanService::class)->handOut($record, auth()->user()));
                        Notification::make()->title('Ausgegeben')->success()->send();
                    }),
                Actions\Action::make('return')
                    ->label('Zurücknehmen')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn (Loan $record) => $record->status === LoanStatus::Active)
                    ->schema([
                        Forms\Components\Textarea::make('damage')->label('Schaden festgestellt?')->rows(2)
                            ->helperText('Leer lassen, wenn alles in Ordnung ist. Sonst wird ein Schaden angelegt und das Gerät gesperrt.'),
                    ])
                    ->action(function (Loan $record, array $data, Actions\Action $action) {
                        EquipmentActions::attempt($action, fn () => app(LoanService::class)->return($record, auth()->user(), $data['damage'] ?? null));
                        Notification::make()->title('Zurückgenommen')->success()->send();
                    }),
                Actions\Action::make('cancel')
                    ->label('Stornieren')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Loan $record) => $record->status === LoanStatus::Reserved)
                    ->action(fn (Loan $record) => app(LoanService::class)->cancel($record, auth()->user())),
            ]);
    }

    /** Reserve an item for a Kamerad for a later period */
    public static function reserveAction(?callable $equipment = null): Actions\Action
    {
        return Actions\Action::make('reserve')
            ->label('Reservieren')
            ->icon('heroicon-o-calendar')
            ->color('gray')
            ->schema(array_filter([
                $equipment ? null : Forms\Components\Select::make('equipment_id')->label('Gerät')
                    ->options(fn () => Equipment::whereIn('status', [EquipmentStatus::Available->value, EquipmentStatus::Loaned->value])
                        ->orderBy('code')->get()->mapWithKeys(fn (Equipment $e) => [$e->id => $e->code.' – '.$e->name]))
                    ->searchable()->required(),
                Forms\Components\Select::make('user_id')->label('Für')->options(fn () => EquipmentActions::members())->searchable()->required(),
                Forms\Components\DateTimePicker::make('starts_at')->label('Von')->required()->native(false)->displayFormat('d.m.Y H:i')->seconds(false)->timezone('Europe/Berlin')
                    ->default(now()->addDay()->setTime(8, 0)),
                Forms\Components\DateTimePicker::make('due_at')->label('Bis')->required()->native(false)->displayFormat('d.m.Y H:i')->seconds(false)->timezone('Europe/Berlin')
                    ->default(now()->addDays(2)->setTime(18, 0))->after('starts_at'),
                Forms\Components\Textarea::make('notes')->label('Notiz')->rows(2),
            ]))
            ->action(function (array $data, $livewire, Actions\Action $action) use ($equipment) {
                $item = $equipment ? $equipment($livewire) : Equipment::findOrFail($data['equipment_id']);
                EquipmentActions::attempt($action, fn () => app(LoanService::class)->reserve(
                    $item, User::findOrFail($data['user_id']), auth()->user(),
                    Carbon::parse($data['starts_at']), Carbon::parse($data['due_at']), $data['notes'] ?? null,
                ));
                Notification::make()->title('Reserviert')->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLoans::route('/'),
        ];
    }
}

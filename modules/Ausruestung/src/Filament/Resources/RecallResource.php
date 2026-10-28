<?php

namespace Modules\Ausruestung\src\Filament\Resources;

use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Ausruestung\src\Filament\Concerns\AusruestungArea;
use Modules\Ausruestung\src\Filament\Resources\RecallResource\Pages;
use Modules\Ausruestung\src\Filament\Resources\RecallResource\RelationManagers\AffectedEquipmentRelationManager;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Ausruestung\src\Models\Recall;
use Modules\Ausruestung\src\Services\EquipmentService;
use Modules\Ausruestung\src\Services\RecallService;

class RecallResource extends Resource
{
    use AusruestungArea;

    protected static ?string $model = Recall::class;

    protected static ?string $slug = 'ausruestung/rueckrufe';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationLabel = 'Rückrufe';

    protected static ?string $modelLabel = 'Rückruf';

    protected static ?string $pluralModelLabel = 'Rückrufe';

    protected static ?int $navigationSort = 40;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return static::canAccessArea() && $user instanceof User && EquipmentService::canManage($user);
    }

    public static function canCreate(): bool
    {
        return EquipmentService::canManageGlobal(auth()->user());
    }

    public static function canEdit(Model $record): bool
    {
        $user = auth()->user();

        return $record instanceof Recall && $user instanceof User && EquipmentService::canManage($user);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof Recall && EquipmentService::canManageGlobal(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Rückruf')
                ->disabled(fn () => ! EquipmentService::canManageGlobal(auth()->user()))
                ->schema([
                    Forms\Components\TextInput::make('title')->label('Titel')->required()->maxLength(255)->columnSpan(2),
                    Forms\Components\DatePicker::make('issued_at')->label('Veröffentlicht am')->native(false)->displayFormat('d.m.Y'),
                    Forms\Components\TextInput::make('url')->label('Link zur Meldung')->url()->maxLength(255)->columnSpan(2),
                    Forms\Components\Textarea::make('description')->label('Beschreibung / Maßnahme')->rows(3)->columnSpanFull(),
                ])
                ->columns(['default' => 1, 'md' => 3])
                ->columnSpanFull(),

            Section::make('Betroffene Geräte')
                ->description('Gesperrt werden alle Geräte dieses Herstellers, die zu allen ausgefüllten Angaben passen. Leere Felder schränken nicht ein. Groß-/Kleinschreibung zählt nicht.')
                ->disabled(fn () => ! EquipmentService::canManageGlobal(auth()->user()))
                ->schema([
                    Forms\Components\TextInput::make('manufacturer')->label('Hersteller')->required()->maxLength(255)
                        ->datalist(fn () => Equipment::whereNotNull('manufacturer')->distinct()->orderBy('manufacturer')->pluck('manufacturer')->all()),
                    Forms\Components\TextInput::make('model')->label('Modell / Typ')->maxLength(255)
                        ->datalist(fn () => Equipment::whereNotNull('model')->distinct()->orderBy('model')->pluck('model')->all()),
                    Forms\Components\TextInput::make('batch_number')->label('Charge / Los')->maxLength(255),
                    Forms\Components\TextInput::make('serial_from')->label('Seriennummer von')->maxLength(255),
                    Forms\Components\TextInput::make('serial_to')->label('Seriennummer bis')->maxLength(255),
                    Forms\Components\DatePicker::make('manufactured_from')->label('Hergestellt ab')->native(false)->displayFormat('d.m.Y'),
                    Forms\Components\DatePicker::make('manufactured_to')->label('Hergestellt bis')->native(false)->displayFormat('d.m.Y'),
                ])
                ->columns(['default' => 1, 'md' => 3])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $user = auth()->user();
        $locationIds = $user instanceof User && ! EquipmentService::canManageGlobal($user)
            ? EquipmentService::manageableLocationIds($user)
            : null;
        $scopeEquipment = fn (Builder $query) => $locationIds === null
            ? $query
            : $query->whereIn('ar_equipment.ext_location_id', $locationIds);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount([
                'equipment as affected_count' => $scopeEquipment,
                'equipment as open_count' => fn (Builder $query) => $scopeEquipment($query)->whereNull('ar_recall_equipment.resolved_at'),
            ]))
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Rückruf')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('manufacturer')->label('Hersteller')->searchable()
                    ->description(fn (Recall $record) => $record->model),
                Tables\Columns\TextColumn::make('issued_at')->label('Veröffentlicht')->date('d.m.Y')->placeholder('–'),
                Tables\Columns\TextColumn::make('affected_count')->label('Geräte'),
                Tables\Columns\TextColumn::make('open_count')->label('Offen')->badge()->color(fn ($state) => $state > 0 ? 'danger' : 'success'),
                Tables\Columns\TextColumn::make('closed_at')->label('Geschlossen')->dateTime('d.m.Y')->timezone('Europe/Berlin')->placeholder('offen'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('open')->label('Status')->placeholder('Alle')->trueLabel('Offen')->falseLabel('Geschlossen')->default(true)
                    ->queries(true: fn ($q) => $q->whereNull('closed_at'), false: fn ($q) => $q->whereNotNull('closed_at'), blank: fn ($q) => $q),
            ])
            ->recordActions([
                Actions\EditAction::make()->label('Öffnen'),
                static::closeAction(),
            ]);
    }

    public static function closeAction(): Actions\Action
    {
        return Actions\Action::make('close')
            ->label('Schließen')
            ->icon('heroicon-o-lock-closed')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('Alle noch gesperrten Geräte dieses Rückrufs werden freigegeben und bekommen ihren vorherigen Status zurück.')
            ->visible(fn (Recall $record) => $record->isOpen() && EquipmentService::canManageGlobal(auth()->user()))
            ->action(function (Recall $record) {
                app(RecallService::class)->close($record, auth()->user());
                Notification::make()->title('Rückruf geschlossen')->success()->send();
            });
    }

    public static function getRelations(): array
    {
        return [AffectedEquipmentRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRecalls::route('/'),
            'create' => Pages\CreateRecall::route('/neu'),
            'edit' => Pages\EditRecall::route('/{record}/bearbeiten'),
        ];
    }
}

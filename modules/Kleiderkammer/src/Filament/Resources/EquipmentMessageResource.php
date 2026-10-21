<?php

namespace Modules\Kleiderkammer\src\Filament\Resources;

use Filament\Actions;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Modules\Kleiderkammer\src\Filament\Concerns\KleiderkammerArea;
use Modules\Kleiderkammer\src\Filament\Resources\EquipmentMessageResource\Pages;
use Modules\Kleiderkammer\src\Models\EquipmentMessage;
use Modules\Kleiderkammer\src\Services\EquipmentMessageService;
use Modules\Kleiderkammer\src\Services\PersonalAccess;

class EquipmentMessageResource extends Resource
{
    use KleiderkammerArea;

    protected static ?string $model = EquipmentMessage::class;

    protected static ?string $slug = 'kleiderkammer/maengelmanagement';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Mängel & Wäsche';

    protected static ?string $modelLabel = 'Meldung';

    protected static ?string $pluralModelLabel = 'Mängel & Wäsche';

    protected static ?int $navigationSort = 11;

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
        $open = EquipmentMessage::whereNull('handled_at')->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Eingegangen')
                    ->dateTime('d.m.Y H:i')
                    ->timezone('Europe/Berlin')
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Mitglied')
                    ->searchable()
                    ->url(fn (EquipmentMessage $record) => MemberResource::getUrl('edit', ['record' => $record->user_id])),

                Tables\Columns\TextColumn::make('kind')
                    ->label('Art')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => EquipmentMessage::KINDS[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'washing' ? 'info' : 'warning'),

                Tables\Columns\TextColumn::make('item_label')
                    ->label('Artikel')
                    ->searchable(),

                Tables\Columns\TextColumn::make('body')
                    ->label('Nachricht / Begründung')
                    ->limit(60),

                Tables\Columns\IconColumn::make('handled_at')
                    ->label('Erledigt')
                    ->boolean()
                    ->getStateUsing(fn (EquipmentMessage $record) => $record->isHandled()),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('kind')->label('Art')->options(EquipmentMessage::KINDS),
                Tables\Filters\TernaryFilter::make('handled')
                    ->label('Status')
                    ->placeholder('Alle')
                    ->trueLabel('Erledigt')
                    ->falseLabel('Offen')
                    ->default(false)
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('handled_at'),
                        false: fn ($query) => $query->whereNull('handled_at'),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->recordAction('open')
            ->actions([
                Actions\Action::make('open')
                    ->label('Lesen')
                    ->icon('heroicon-o-envelope-open')
                    ->color('gray')
                    ->modalHeading(fn (EquipmentMessage $record) => $record->kindLabel().': '.$record->item_label)
                    ->modalDescription(fn (EquipmentMessage $record) => $record->user->name.' · '.$record->created_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i'))
                    ->modalContent(fn (EquipmentMessage $record) => new HtmlString('<p style="white-space:pre-line">'.e($record->body).'</p>'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Schließen'),

                Actions\Action::make('washed')
                    ->label('Gewaschen')
                    ->icon('heroicon-o-sparkles')
                    ->visible(fn (EquipmentMessage $record) => $record->isWashing() && ! $record->isHandled())
                    ->disabled(fn (EquipmentMessage $record) => app(PersonalAccess::class)->enabled()
                        && $record->issuedItem?->item_id !== null
                        && ! app(PersonalAccess::class)->canManageCatalog(auth()->user()))
                    ->tooltip(fn (EquipmentMessage $record) => app(PersonalAccess::class)->enabled()
                        && $record->issuedItem?->item_id !== null
                        && ! app(PersonalAccess::class)->canManageCatalog(auth()->user())
                            ? 'Bei Artikeln mit Code dürfen nur globale Admins die Wäsche abschließen, da dabei personenbezogene Pflegehistorie gespeichert wird.'
                            : null)
                    ->requiresConfirmation()
                    ->modalDescription('Das Mitglied bekommt Bescheid; ein Artikel mit Code bekommt die Wäsche in seine Pflegehistorie.')
                    ->action(fn (EquipmentMessage $record) => app(EquipmentMessageService::class)->markWashed($record, auth()->user())),

                Actions\Action::make('handle')
                    ->label('Erledigt')
                    ->icon('heroicon-o-check')
                    ->visible(fn (EquipmentMessage $record) => ! $record->isWashing() && ! $record->isHandled())
                    ->action(fn (EquipmentMessage $record) => $record->update([
                        'handled_at' => now(),
                        'handled_by' => auth()->id(),
                    ])),

                Actions\Action::make('reopen')
                    ->label('Wieder öffnen')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (EquipmentMessage $record) => $record->isHandled())
                    ->action(fn (EquipmentMessage $record) => $record->update(['handled_at' => null, 'handled_by' => null])),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEquipmentMessages::route('/'),
        ];
    }
}

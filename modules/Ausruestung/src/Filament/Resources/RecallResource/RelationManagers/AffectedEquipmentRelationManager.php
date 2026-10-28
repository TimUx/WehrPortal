<?php

namespace Modules\Ausruestung\src\Filament\Resources\RecallResource\RelationManagers;

use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Ausruestung\src\Enums\EquipmentStatus;
use Modules\Ausruestung\src\Filament\Resources\EquipmentResource;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Ausruestung\src\Services\EquipmentService;
use Modules\Ausruestung\src\Services\RecallService;

class AffectedEquipmentRelationManager extends RelationManager
{
    protected static string $relationship = 'equipment';

    protected static ?string $title = 'Betroffene Geräte';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(function ($query) {
                $user = auth()->user();
                if ($user instanceof User && ! EquipmentService::canManageGlobal($user)) {
                    $query->whereIn('ar_equipment.ext_location_id', EquipmentService::manageableLocationIds($user));
                }
            })
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Code')->fontFamily('mono')
                    ->url(fn (Equipment $record) => EquipmentResource::getUrl('edit', ['record' => $record])),
                Tables\Columns\TextColumn::make('name')->label('Gerät')->searchable(),
                Tables\Columns\TextColumn::make('serial_number')->label('Seriennummer')->placeholder('–'),
                Tables\Columns\TextColumn::make('place')->label('Standort')->state(fn (Equipment $record) => $record->placeLabel()),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (EquipmentStatus $state) => $state->label())->color(fn (EquipmentStatus $state) => $state->color()),
                Tables\Columns\TextColumn::make('pivot.resolution')->label('Erledigung')->placeholder('offen')->wrap(),
            ])
            ->headerActions([
                Actions\Action::make('rematch')
                    ->label('Bestand erneut abgleichen')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn () => $this->getOwnerRecord()->isOpen() && EquipmentService::canManageGlobal(auth()->user()))
                    ->action(function () {
                        $count = app(RecallService::class)->apply($this->getOwnerRecord(), auth()->user());
                        Notification::make()->title($count > 0 ? $count.' weitere(s) Gerät(e) gesperrt' : 'Keine weiteren Geräte gefunden')->send();
                    }),
            ])
            ->recordActions([
                Actions\Action::make('resolve')
                    ->label('Erledigt')
                    ->icon('heroicon-o-check')
                    ->visible(fn (Equipment $record) => $record->pivot->resolved_at === null
                        && auth()->user() instanceof User
                        && EquipmentService::canManageEquipment(auth()->user(), $record))
                    ->schema([Forms\Components\Textarea::make('resolution')->label('Was wurde gemacht?')->rows(2)->placeholder('z. B. geprüft, nicht betroffen / Bauteil getauscht')])
                    ->action(function (Equipment $record, array $data) {
                        app(RecallService::class)->resolve($this->getOwnerRecord(), $record, auth()->user(), $data['resolution'] ?? null);
                        Notification::make()->title('Erledigt – Gerät freigegeben, sofern kein anderer Rückruf es sperrt')->success()->send();
                    }),
            ]);
    }
}

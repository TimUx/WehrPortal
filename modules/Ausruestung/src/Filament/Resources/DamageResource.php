<?php

namespace Modules\Ausruestung\src\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Ausruestung\src\Enums\DamageStatus;
use Modules\Ausruestung\src\Filament\Concerns\AusruestungArea;
use Modules\Ausruestung\src\Filament\Resources\DamageResource\Pages;
use Modules\Ausruestung\src\Models\Damage;
use Modules\Ausruestung\src\Services\EquipmentService;

/** Schadenmeldungen and their repair */
class DamageResource extends Resource
{
    use AusruestungArea;

    protected static ?string $model = Damage::class;

    protected static ?string $slug = 'ausruestung/schaeden';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-wrench';

    protected static ?string $navigationLabel = 'Schäden & Reparatur';

    protected static ?string $modelLabel = 'Schaden';

    protected static ?string $pluralModelLabel = 'Schäden';

    protected static ?int $navigationSort = 30;

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
        $open = Damage::where('status', DamageStatus::Open->value)->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        $change = fn (string $name, string $label, string $icon, DamageStatus $status, array $visibleFrom, bool $withRepairData = false) => Actions\Action::make($name)
            ->label($label)
            ->icon($icon)
            ->visible(fn (Damage $record) => in_array($record->status, $visibleFrom, true))
            ->schema($withRepairData ? [
                Forms\Components\Textarea::make('repair_notes')->label('Was wurde gemacht?')->rows(2),
                Forms\Components\TextInput::make('repair_cost')->label('Kosten')->numeric()->minValue(0)->suffix('€'),
            ] : [Forms\Components\Textarea::make('repair_notes')->label('Notiz')->rows(2)])
            ->action(function (Damage $record, array $data) use ($status) {
                app(EquipmentService::class)->updateDamage($record, auth()->user(), $status, $data['repair_notes'] ?? null, isset($data['repair_cost']) && $data['repair_cost'] !== '' ? (float) $data['repair_cost'] : null);
                Notification::make()->title($status->label())->success()->send();
            });

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['equipment', 'reporter']))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Gemeldet')->dateTime('d.m.Y H:i')->timezone('Europe/Berlin')->sortable(),
                Tables\Columns\TextColumn::make('equipment.name')->label('Gerät')->description(fn (Damage $record) => $record->equipment->code)
                    ->searchable(['name', 'code']),
                Tables\Columns\TextColumn::make('description')->label('Schaden')->wrap()->limit(80),
                Tables\Columns\TextColumn::make('reporter.name')->label('Von')->placeholder('–'),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (DamageStatus $state) => $state->label())
                    ->color(fn (DamageStatus $state) => $state->color()),
                Tables\Columns\TextColumn::make('repair_notes')->label('Reparatur')->wrap()->placeholder('–')->toggleable(),
                Tables\Columns\TextColumn::make('repair_cost')->label('Kosten')->money('EUR', locale: 'de')->placeholder('–')->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Status')->options(DamageStatus::options())->multiple()
                    ->default([DamageStatus::Open->value, DamageStatus::InRepair->value]),
            ])
            ->recordActions([
                $change('startRepair', 'In Reparatur', 'heroicon-o-wrench', DamageStatus::InRepair, [DamageStatus::Open]),
                $change('repaired', 'Repariert', 'heroicon-o-check', DamageStatus::Repaired, [DamageStatus::Open, DamageStatus::InRepair], true),
                $change('scrap', 'Aussondern', 'heroicon-o-archive-box-x-mark', DamageStatus::Scrapped, [DamageStatus::Open, DamageStatus::InRepair])
                    ->color('danger')->requiresConfirmation()->modalDescription('Das Gerät wird ausgesondert.'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListDamages::route('/')];
    }
}

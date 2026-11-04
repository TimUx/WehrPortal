<?php

namespace Modules\Atemschutz\src\Filament\Resources\EquipmentResource\Pages;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Modules\Atemschutz\src\Filament\Resources\EquipmentResource;
use Modules\Atemschutz\src\Models\EquipmentType;
use Modules\Atemschutz\src\Services\EquipmentService;

class ListEquipment extends ListRecords
{
    protected static string $resource = EquipmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('typeSettings')
                ->label('Gerätetypen / Mindestfülldruck')
                ->icon('heroicon-o-adjustments-horizontal')
                ->modalHeading('Atemschutz-Gerätetyp konfigurieren')
                ->form([
                    Forms\Components\Select::make('type_id')->label('Gerätetyp')
                        ->options(fn () => EquipmentType::query()->orderBy('name')->pluck('name', 'id'))
                        ->required()->live()
                        ->afterStateUpdated(function ($state, Set $set): void {
                            $type = EquipmentType::query()->find($state);
                            if ($type) {
                                $set('name', $type->name);
                                $set('minimum_fill_pressure_bar', $type->minimum_fill_pressure_bar);
                                $set('is_active', $type->is_active);
                            }
                        }),
                    Forms\Components\TextInput::make('name')->label('Bezeichnung')->required()->maxLength(120),
                    Forms\Components\TextInput::make('minimum_fill_pressure_bar')->label('Mindestfülldruck')
                        ->numeric()->minValue(0)->maxValue(65535)->suffix('bar')
                        ->visible(fn (Get $get) => EquipmentType::query()->whereKey($get('type_id'))->value('family') === 'cylinder')
                        ->helperText('Es wird kein Standardwert angenommen. Am Mindestwert ist die Flasche füllstandsseitig einsatzfähig.'),
                    Forms\Components\Toggle::make('is_active')->label('Gerätetyp aktiv')->default(true)->required(),
                ])
                ->action(function (array $data): void {
                    $type = EquipmentType::query()->findOrFail($data['type_id']);
                    app(EquipmentService::class)->updateType($type, $data, auth()->user());
                }),
            Actions\CreateAction::make()->label('Atemschutzgerät anlegen'),
        ];
    }

    public function getSubheading(): ?string
    {
        return 'Eigenständiger Atemschutzbestand; allgemeine Ausrüstung und Wartungsprotokolle werden nicht übernommen.';
    }
}

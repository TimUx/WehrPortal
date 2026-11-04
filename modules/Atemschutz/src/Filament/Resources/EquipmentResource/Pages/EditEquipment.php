<?php

namespace Modules\Atemschutz\src\Filament\Resources\EquipmentResource\Pages;

use App\Support\QrCode;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Atemschutz\src\Filament\Resources\EquipmentResource;
use Modules\Atemschutz\src\Models\Equipment;
use Modules\Atemschutz\src\Services\EquipmentService;

class EditEquipment extends EditRecord
{
    protected static string $resource = EquipmentResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Equipment) {
            throw new \LogicException('The Atemschutz resource must edit an Atemschutz equipment record.');
        }

        $components = $data['component_ids'] ?? [];
        unset($data['component_ids']);

        return DB::transaction(function () use ($record, $data, $components) {
            $service = app(EquipmentService::class);
            $updated = $service->updateEquipment($record, $data, auth()->user());
            $service->syncComponents($updated, $components, auth()->user());

            return $updated;
        });
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('label')
                ->label('QR-Etikett')
                ->icon('heroicon-o-qr-code')
                ->modalHeading('Atemschutzgerät kennzeichnen')
                ->modalContent(fn (Equipment $record) => view('atemschutz::filament.label', [
                    'equipment' => $record,
                    'qr' => QrCode::dataUri(route('scan.code', $record->code), 220),
                ]))
                ->modalSubmitAction(false),
            Actions\Action::make('sendToWorkshop')
                ->label('In Werkstatt geben')
                ->icon('heroicon-o-wrench-screwdriver')
                ->visible(fn (Equipment $record) => ! in_array($record->status, [
                    Equipment::STATUS_IN_REPAIR,
                    Equipment::STATUS_RETIRED,
                ], true))
                ->form([
                    Forms\Components\TextInput::make('workshop')->label('Werkstatt / Zielort')->required()->maxLength(180),
                    Forms\Components\Textarea::make('reason')->label('Grund')->required()->maxLength(10000),
                ])
                ->action(function (Equipment $record, array $data): void {
                    app(EquipmentService::class)->sendToWorkshop($record, $data['workshop'], $data['reason'], auth()->user());
                    $this->refreshFormData(['status', 'physical_location']);
                }),
            Actions\Action::make('returnFromWorkshop')
                ->label('Aus Werkstatt zurück')
                ->icon('heroicon-o-arrow-uturn-left')
                ->visible(fn (Equipment $record) => $record->status === Equipment::STATUS_IN_REPAIR)
                ->form([
                    Forms\Components\TextInput::make('destination')->label('Rückgabeort')->required()->maxLength(180),
                    Forms\Components\Select::make('status')->label('Bestätigter Zustand')
                        ->options([
                            Equipment::STATUS_AVAILABLE => 'Einsatzbereit',
                            Equipment::STATUS_DEFECTIVE => 'Defekt / gesperrt',
                        ])->required(),
                    Forms\Components\Checkbox::make('confirmed')->label('Zustand und Rückgabe geprüft')->required()->rule('accepted'),
                    Forms\Components\Textarea::make('notes')->label('Rückgabevermerk')->required()->maxLength(10000),
                ])
                ->action(function (Equipment $record, array $data): void {
                    app(EquipmentService::class)->returnFromWorkshop(
                        $record,
                        $data['destination'],
                        $data['status'],
                        $data['confirmed'],
                        $data['notes'],
                        auth()->user(),
                    );
                    $this->refreshFormData(['status', 'physical_location']);
                }),
            Actions\Action::make('markDefective')
                ->label('Defekt / sperren')
                ->color('danger')
                ->icon('heroicon-o-no-symbol')
                ->visible(fn (Equipment $record) => ! in_array($record->status, [
                    Equipment::STATUS_DEFECTIVE,
                    Equipment::STATUS_RETIRED,
                ], true))
                ->form([
                    Forms\Components\Textarea::make('reason')->label('Grund')->required()->maxLength(10000),
                ])
                ->action(function (Equipment $record, array $data): void {
                    app(EquipmentService::class)->changeStatus(
                        $record,
                        Equipment::STATUS_DEFECTIVE,
                        $data['reason'],
                        auth()->user(),
                    );
                    $this->refreshFormData(['status']);
                }),
            Actions\Action::make('retire')
                ->label('Aussondern')
                ->color('gray')
                ->icon('heroicon-o-archive-box')
                ->requiresConfirmation()
                ->visible(fn (Equipment $record) => $record->status !== Equipment::STATUS_RETIRED)
                ->form([
                    Forms\Components\Textarea::make('reason')->label('Grund')->required()->maxLength(10000),
                ])
                ->action(function (Equipment $record, array $data): void {
                    app(EquipmentService::class)->changeStatus(
                        $record,
                        Equipment::STATUS_RETIRED,
                        $data['reason'],
                        auth()->user(),
                    );
                    $this->refreshFormData(['status']);
                }),
        ];
    }
}

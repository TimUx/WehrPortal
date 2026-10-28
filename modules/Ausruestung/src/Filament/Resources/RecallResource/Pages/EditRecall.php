<?php

namespace Modules\Ausruestung\src\Filament\Resources\RecallResource\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Modules\Ausruestung\src\Filament\Resources\RecallResource;
use Modules\Ausruestung\src\Services\EquipmentService;
use Modules\Ausruestung\src\Services\RecallService;

class EditRecall extends EditRecord
{
    protected static string $resource = RecallResource::class;

    protected function getHeaderActions(): array
    {
        return [RecallResource::closeAction()];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->visible(fn () => EquipmentService::canManageGlobal(auth()->user()));
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        abort_unless(EquipmentService::canManageGlobal(auth()->user()), 403);

        return $data;
    }

    /** Wider criteria may hit more items; narrower ones never unlock anything by themselves */
    protected function afterSave(): void
    {
        if (! EquipmentService::canManageGlobal(auth()->user())) {
            return;
        }

        $count = app(RecallService::class)->apply($this->record, auth()->user());

        if ($count > 0) {
            Notification::make()->title($count.' weitere(s) Gerät(e) gefunden und gesperrt')->warning()->send();
        }
    }
}

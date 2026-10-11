<?php

namespace Modules\Ausruestung\src\Filament\Resources\RecallResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Modules\Ausruestung\src\Filament\Resources\RecallResource;
use Modules\Ausruestung\src\Services\RecallService;

class EditRecall extends EditRecord
{
    protected static string $resource = RecallResource::class;

    protected function getHeaderActions(): array
    {
        return [RecallResource::closeAction()];
    }

    /** Wider criteria may hit more items; narrower ones never unlock anything by themselves */
    protected function afterSave(): void
    {
        $count = app(RecallService::class)->apply($this->record, auth()->user());

        if ($count > 0) {
            Notification::make()->title($count.' weitere(s) Gerät(e) gefunden und gesperrt')->warning()->send();
        }
    }
}

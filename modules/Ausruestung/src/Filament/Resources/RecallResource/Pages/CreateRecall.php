<?php

namespace Modules\Ausruestung\src\Filament\Resources\RecallResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Modules\Ausruestung\src\Filament\Resources\RecallResource;
use Modules\Ausruestung\src\Services\RecallService;

class CreateRecall extends CreateRecord
{
    protected static string $resource = RecallResource::class;

    protected function afterCreate(): void
    {
        $count = app(RecallService::class)->apply($this->record, auth()->user());

        Notification::make()
            ->title($count > 0 ? $count.' Gerät(e) gefunden und gesperrt' : 'Kein passendes Gerät im Bestand')
            ->{$count > 0 ? 'warning' : 'success'}()
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}

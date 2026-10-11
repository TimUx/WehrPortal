<?php

namespace Modules\Fahrzeugbuchung\src\Filament\Resources\MailTemplateResource\Pages;

use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Modules\Fahrzeugbuchung\src\Filament\Resources\MailTemplateResource;

class EditMailTemplate extends EditRecord
{
    protected static string $resource = MailTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('reset')
                ->label('Standard wiederherstellen')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Der Text wird auf die ursprüngliche Fassung zurückgesetzt. Deine Änderungen gehen verloren.')
                ->action(function () {
                    $this->record->resetToDefault();
                    $this->fillForm();
                    Notification::make()->title('Standardtext wiederhergestellt')->success()->send();
                }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

<?php

namespace Modules\Kleiderkammer\src\Filament\Resources\ItemTypeResource\Pages;

use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Modules\Kleiderkammer\src\Filament\Resources\ItemTypeResource;

class EditItemType extends EditRecord
{
    protected static string $resource = ItemTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Löschen')
                ->before(function (Actions\DeleteAction $action) {
                    // Schwachstelle #3: Löschen sperren wenn Teile oder Vorgänge existieren
                    if ($this->record->items()->exists() || $this->record->issuedItems()->exists()) {
                        Notification::make()
                            ->title('Artikeltyp kann nicht gelöscht werden')
                            ->body('Es existieren Teile oder Ausgabevorgänge für diesen Artikeltyp.')
                            ->danger()
                            ->send();
                        $action->cancel();
                    }
                }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

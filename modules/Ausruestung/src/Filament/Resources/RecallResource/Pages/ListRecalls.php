<?php

namespace Modules\Ausruestung\src\Filament\Resources\RecallResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Modules\Ausruestung\src\Filament\Resources\RecallResource;

class ListRecalls extends ListRecords
{
    protected static string $resource = RecallResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Rückruf erfassen')];
    }

    public function getSubheading(): ?string
    {
        return 'Rückrufmeldungen der Hersteller hier erfassen. Passende Geräte werden sofort gefunden und gesperrt – auch Geräte, die später erfasst werden.';
    }
}

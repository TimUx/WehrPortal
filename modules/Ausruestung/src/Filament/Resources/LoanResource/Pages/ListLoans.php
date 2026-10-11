<?php

namespace Modules\Ausruestung\src\Filament\Resources\LoanResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Ausruestung\src\Filament\Resources\LoanResource;

class ListLoans extends ListRecords
{
    protected static string $resource = LoanResource::class;

    protected function getHeaderActions(): array
    {
        return [LoanResource::reserveAction()];
    }

    public function getSubheading(): ?string
    {
        return 'Sofort ausleihen geht über das Gerät („Ausleihen“); hier stehen alle Ausleihen und Reservierungen.';
    }
}

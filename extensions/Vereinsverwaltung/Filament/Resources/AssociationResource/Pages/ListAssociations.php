<?php

namespace Extensions\Vereinsverwaltung\Filament\Resources\AssociationResource\Pages;

use Extensions\Vereinsverwaltung\Filament\Resources\AssociationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAssociations extends ListRecords
{
    protected static string $resource = AssociationResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Verein anlegen')];
    }
}

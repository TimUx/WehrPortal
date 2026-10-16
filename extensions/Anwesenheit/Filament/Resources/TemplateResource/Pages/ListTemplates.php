<?php

namespace Extensions\Anwesenheit\Filament\Resources\TemplateResource\Pages;

use Extensions\Anwesenheit\Filament\Resources\TemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTemplates extends ListRecords
{
    protected static string $resource = TemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Vorlage anlegen')];
    }
}

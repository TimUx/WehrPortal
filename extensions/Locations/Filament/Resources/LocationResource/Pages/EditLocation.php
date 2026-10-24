<?php

namespace Extensions\Locations\Filament\Resources\LocationResource\Pages;

use Extensions\Locations\Filament\Resources\LocationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLocation extends EditRecord
{
    protected static string $resource = LocationResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return LocationResource::prepareBranding($data);
    }

    protected function afterSave(): void
    {
        $this->data['crest_upload'] = [];
        $this->data['remove_crest'] = false;
    }

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}

<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    private array $moduleFormState = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->moduleFormState = Arr::where($data, fn ($v, $k) => str_starts_with($k, 'module_access_') || str_starts_with($k, 'module_role_'));

        return Arr::except($data, array_keys($this->moduleFormState));
    }

    protected function afterCreate(): void
    {
        UserResource::syncModuleAccess($this->record, $this->moduleFormState);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

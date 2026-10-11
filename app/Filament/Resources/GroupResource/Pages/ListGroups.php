<?php

namespace App\Filament\Resources\GroupResource\Pages;

use App\Filament\Concerns\TransfersMemberStructure;
use App\Filament\Resources\GroupResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListGroups extends ListRecords
{
    use TransfersMemberStructure;

    protected static string $resource = GroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->transferActions('groups'),
            Actions\CreateAction::make()->label('Abteilung anlegen'),
        ];
    }
}

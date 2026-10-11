<?php

namespace Modules\Kleiderkammer\src\Filament\Resources\MemberResource\Pages;

use Filament\Resources\Pages\EditRecord;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource;

class EditMember extends EditRecord
{
    protected static string $resource = MemberResource::class;

    public function getTitle(): string
    {
        return 'Kleiderkammerprofil: '.$this->getRecord()->name;
    }

    // Only the notes are editable here; never let anything else through to the user record.
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ['notes' => $data['notes'] ?? null];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}

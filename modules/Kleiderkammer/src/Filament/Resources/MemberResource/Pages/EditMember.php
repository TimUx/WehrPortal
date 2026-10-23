<?php

namespace Modules\Kleiderkammer\src\Filament\Resources\MemberResource\Pages;

use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource;
use Modules\Kleiderkammer\src\Services\PersonalAccess;

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
        $record = $this->getRecord();
        abort_unless($record instanceof User, 403);
        app(PersonalAccess::class)->authorizeMember(auth()->user(), $record);

        if (MemberResource::minimalProfile()) {
            return [];
        }

        return ['notes' => $data['notes'] ?? null];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}

<?php

namespace Modules\Kleiderkammer\src\Filament\Resources\MemberResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource;

class ListMembers extends ListRecords
{
    protected static string $resource = MemberResource::class;

    public function getSubheading(): ?string
    {
        return 'Mitglieder werden in der Kameradenverwaltung der Suite gepflegt.';
    }
}

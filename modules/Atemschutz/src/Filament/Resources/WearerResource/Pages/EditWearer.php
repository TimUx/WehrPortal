<?php

namespace Modules\Atemschutz\src\Filament\Resources\WearerResource\Pages;

use Filament\Resources\Pages\EditRecord;
use Modules\Atemschutz\src\Filament\Resources\WearerResource;
use Modules\Atemschutz\src\Models\Wearer;

class EditWearer extends EditRecord
{
    protected static string $resource = WearerResource::class;

    public function getTitle(): string
    {
        /** @var Wearer $wearer */
        $wearer = $this->getRecord();

        return $wearer->user->name;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

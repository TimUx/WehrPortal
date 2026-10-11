<?php

namespace Modules\Fahrzeugbuchung\src\Filament\Resources\MailTemplateResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Fahrzeugbuchung\src\Filament\Resources\MailTemplateResource;
use Modules\Fahrzeugbuchung\src\Models\MailTemplate;

class ListMailTemplates extends ListRecords
{
    protected static string $resource = MailTemplateResource::class;

    public function mount(): void
    {
        // Every known mail shows up here, even before it was sent for the first time
        MailTemplate::ensureDefaults();

        parent::mount();
    }
}

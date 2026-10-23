<?php

namespace Extensions\Anwesenheit\Filament\Resources\TemplateResource\Pages;

use Extensions\Anwesenheit\Filament\Resources\TemplateResource;
use Extensions\Anwesenheit\Services\AttendanceService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTemplate extends CreateRecord
{
    protected static string $resource = TemplateResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        abort_unless(AttendanceService::canManageAt(auth()->user(), isset($data['ext_location_id']) ? (int) $data['ext_location_id'] : null), 403);

        return parent::handleRecordCreation($data);
    }
}

<?php

namespace Extensions\Anwesenheit\Filament\Resources\TemplateResource\Pages;

use Extensions\Anwesenheit\Filament\Resources\TemplateResource;
use Extensions\Anwesenheit\Services\AttendanceService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditTemplate extends EditRecord
{
    protected static string $resource = TemplateResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless(AttendanceService::canManageAt(auth()->user(), $record->getAttribute('ext_location_id'))
            && AttendanceService::canManageAt(auth()->user(), isset($data['ext_location_id']) ? (int) $data['ext_location_id'] : $record->getAttribute('ext_location_id')), 403);

        return parent::handleRecordUpdate($record, $data);
    }
}

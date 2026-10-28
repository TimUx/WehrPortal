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
        $user = auth()->user();
        $locationId = isset($data['ext_location_id']) ? (int) $data['ext_location_id'] : $record->getAttribute('ext_location_id');
        $groupId = isset($data['group_id']) ? (int) $data['group_id'] : $record->getAttribute('group_id');
        abort_unless(AttendanceService::canManageGroupAt($user, $record->getAttribute('ext_location_id'), $record->getAttribute('group_id'))
            && AttendanceService::canManageGroupAt($user, $locationId, $groupId)
            && app(AttendanceService::class)->canSelectGroup($user, $locationId, $groupId), 403);

        return parent::handleRecordUpdate($record, $data);
    }
}

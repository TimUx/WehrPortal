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
        $user = auth()->user();
        $locationId = isset($data['ext_location_id']) ? (int) $data['ext_location_id'] : null;
        $groupId = isset($data['group_id']) ? (int) $data['group_id'] : null;
        abort_unless(AttendanceService::canManageGroupAt($user, $locationId, $groupId)
            && app(AttendanceService::class)->canSelectGroup($user, $locationId, $groupId), 403);

        return parent::handleRecordCreation($data);
    }
}

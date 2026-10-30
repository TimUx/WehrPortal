<?php

namespace Modules\Atemschutz\src\Filament\Resources\WearerResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Atemschutz\src\Filament\Resources\WearerResource;
use Modules\Atemschutz\src\Models\Wearer;
use Modules\Atemschutz\src\Services\MonitorService;

class CreateWearer extends CreateRecord
{
    protected static string $resource = WearerResource::class;

    // A former Geräteträger is taken back instead of a second row
    protected function handleRecordCreation(array $data): Model
    {
        abort_unless(MonitorService::canCareFor(auth()->user(), (int) $data['user_id']), 403);

        $wearer = Wearer::firstOrNew(['user_id' => $data['user_id']]);
        $wearer->fill($data)->save();

        return $wearer;
    }
}

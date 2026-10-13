<?php

namespace Modules\Maengelmanagement\src\Services;

use App\Services\ImageResizer;
use Modules\Maengelmanagement\src\Models\Defect;
use Modules\Maengelmanagement\src\Models\DefectPhoto;

class DefectPhotoService
{
    public const MAX_PHOTOS = 3;

    private const MAX_SIDE = 1280;

    public function __construct(private readonly ImageResizer $images) {}

    /** Scale to at most 1280 px on the long side and store as WebP */
    public function store(Defect $defect, string $path, ?int $eventId = null): DefectPhoto
    {
        return DefectPhoto::create(['defect_id' => $defect->id, 'event_id' => $eventId, 'mime' => 'image/webp',
            'data' => base64_encode($this->images->toWebp($path, self::MAX_SIDE))]);
    }
}

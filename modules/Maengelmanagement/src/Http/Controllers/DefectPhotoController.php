<?php

namespace Modules\Maengelmanagement\src\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Maengelmanagement\src\Models\DefectPhoto;

/** Serves a defect photo from the database; behind the module's login like everything else */
class DefectPhotoController extends Controller
{
    public function __invoke(DefectPhoto $photo)
    {
        return response(base64_decode($photo->data), 200, [
            'Content-Type' => $photo->mime,
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }
}

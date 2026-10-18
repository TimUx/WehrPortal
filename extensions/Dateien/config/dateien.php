<?php

use App\Support\FileResponse;

return [
    'max_upload_kb' => 20480,
    'extensions' => FileResponse::allowedExtensions(),
    'retention_days' => 30,
];

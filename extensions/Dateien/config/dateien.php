<?php

use App\Support\FileResponse;

return [
    'max_upload_kb' => 20480,
    // Upper bound for the admin setting. PHP (upload_max_filesize, post_max_size) and the reverse
    // proxy must allow at least this much, otherwise uploads fail before they reach the app.
    // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig (extension config, merged like config/)
    'max_upload_limit_kb' => (int) env('DATEIEN_MAX_UPLOAD_LIMIT_KB', 51200),
    'extensions' => FileResponse::allowedExtensions(),
    'retention_days' => 30,
    'quota_warning_percent' => 90,
    // 0 keeps audit events forever
    'audit_retention_days' => 730,
    'verify_downloads' => true,
    // Stored files younger than this are never reported as orphans (uploads still in a transaction)
    'orphan_grace_minutes' => 60,
    'rate_limits' => [
        'uploads_per_minute' => 60,
        'downloads_per_minute' => 300,
    ],
    // Online-Office (milestones 3 and 4). Provider, URLs and secret are admin settings; these are limits.
    'office' => [
        'session_minutes' => 480,
        'timeout' => 5,
        'rate_limit' => 600,
        // Seconds for downloading a saved document from ONLYOFFICE
        'save_timeout' => 60,
        // An edit session without activity for this long no longer blocks manual uploads
        'edit_idle_minutes' => 60,
    ],
];

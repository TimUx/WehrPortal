<?php

return [
    // false: the extension is not loaded at all
    'enabled' => (bool) env('ANWESENHEIT_ENABLED', true),

    // Uploads per list (the files are also attached to the mail)
    'max_files' => 5,
    'max_file_kb' => 5120,

    // The kinds of a list
    'kinds' => [
        'uebung' => 'Übung',
        'dienst' => 'Dienst / Dienstabend',
        'lehrgang' => 'Lehrgang / Unterricht',
        'arbeitsdienst' => 'Arbeitsdienst',
        'sonstiges' => 'Sonstiges',
    ],
];

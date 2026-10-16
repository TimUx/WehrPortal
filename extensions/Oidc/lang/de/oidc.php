<?php

return [
    'default_label' => 'Mit Single Sign-On anmelden',
    'nav' => 'Single Sign-On (OIDC)',

    'error' => [
        'generic' => 'Die Anmeldung über den Identity Provider ist fehlgeschlagen. Bitte versuche es erneut oder wende dich an den Administrator.',
        'unavailable' => 'Der Identity Provider ist derzeit nicht erreichbar.',
        'no_account' => 'Für diese Identität gibt es kein freigegebenes Konto. Bitte wende dich an den Administrator.',
        'inactive' => 'Dein Konto wurde deaktiviert. Bitte wende dich an den Administrator.',
        'unverified' => 'Die E-Mail-Adresse ist beim Identity Provider nicht bestätigt.',
    ],

    'lockout' => 'Der letzte lokale System-Admin kann nicht entfernt, deaktiviert oder herabgestuft werden, solange die Anmeldung per Link für normale Benutzer abgeschaltet ist.',

    'test' => [
        'ok' => 'Discovery erfolgreich: Authorization-, Token- und JWKS-Endpunkt gefunden (:keys Schlüssel).',
        'no_issuer' => 'Bitte zuerst eine Issuer-URL eintragen.',
        'failed' => 'Discovery fehlgeschlagen: :reason',
    ],
];

<?php

return [
    // false: the extension is not loaded at all (no routes, no admin page, no login button)
    'enabled' => (bool) env('OIDC_ENABLED', true),

    // Emergency switch: OIDC login is off and the magic link works for everyone again, whatever the admin settings say
    'force_disabled' => (bool) env('OIDC_FORCE_DISABLED', false),
];

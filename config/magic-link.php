<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Magic Link Token TTL
    |--------------------------------------------------------------------------
    |
    | How many minutes a magic link token remains valid after being issued.
    |
    */

    'ttl_minutes' => (int) env('MAGIC_LINK_TTL_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Maximum number of magic link requests allowed per email address per hour
    | and per IP address per hour.
    |
    */

    'rate_limit_per_email' => 5,

    'rate_limit_per_ip' => 20,

    /*
    |--------------------------------------------------------------------------
    | E-mail change (double opt-in)
    |--------------------------------------------------------------------------
    |
    | How many hours the confirmation link for a new e-mail address stays valid,
    | and how many change requests one Kamerad may make per hour.
    |
    */

    'email_change_ttl_hours' => (int) env('EMAIL_CHANGE_TTL_HOURS', 24),

    'email_change_rate_limit' => 5,

    /*
    |--------------------------------------------------------------------------
    | Token Prune Age
    |--------------------------------------------------------------------------
    |
    | Tokens older than this many days will be pruned by the scheduler.
    |
    */

    'prune_days' => 7,

];

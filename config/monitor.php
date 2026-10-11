<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Station monitor access token
    |--------------------------------------------------------------------------
    |
    | The read-only monitor at /monitor/{token} works without login for the
    | screen at the station. Leave empty to allow admins only. Changing the
    | value invalidates the old link immediately.
    |
    */

    'token' => env('DISPLAY_TOKEN', ''),

];

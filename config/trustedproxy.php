<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | Only these addresses may say who the visitor is (X-Forwarded-For) and
    | whether the request came over HTTPS. The default covers a reverse proxy
    | on the same host or in a Docker network. Everyone else is taken by the
    | address they connect from, so the login and scan limits can't be dodged
    | with a made-up header. Comma-separated addresses or networks; "*" trusts
    | whoever connects (only behind a proxy that can't be bypassed).
    |
    */

    'proxies' => env('TRUSTED_PROXIES') ?: '127.0.0.0/8,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,::1,fc00::/7',

];

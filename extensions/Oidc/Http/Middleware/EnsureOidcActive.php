<?php

namespace Extensions\Oidc\Http\Middleware;

use Closure;
use Extensions\Oidc\Support\OidcSettings;
use Illuminate\Http\Request;

/** Disabled or unconfigured: the OIDC routes do not exist */
class EnsureOidcActive
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(app(OidcSettings::class)->isActive(), 404);

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Support\LinkPreview;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A link preview fetcher asking for a page behind the login gets a small page with the module's
 * name and description instead of the redirect to "Anmelden". Nothing of the content is shown.
 */
class ServeLinkPreview
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && ! $request->user() && LinkPreview::isCrawler($request->userAgent())
            && in_array('auth', $request->route()?->gatherMiddleware() ?? [], true)) {
            return response()->view('link-preview', ['preview' => app(LinkPreview::class)->forRequest($request)]);
        }

        return $next($request);
    }
}

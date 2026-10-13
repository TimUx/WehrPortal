<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nothing of an openwehr instance belongs in a search engine or an AI model: every response (pages,
 * admin area, PDFs, plans, photos, calendar feeds) says so in the header, also where no HTML meta
 * tag can. robots.txt additionally keeps crawlers out.
 */
class NoIndex
{
    public const HEADER = 'noindex, nofollow, noarchive, nosnippet, noimageindex, notranslate, noai, noimageai';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Robots-Tag', self::HEADER);

        return $response;
    }
}

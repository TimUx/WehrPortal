<?php

namespace Extensions\Locations\Http\Controllers;

use Extensions\Locations\Services\LocationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SwitchLocationController
{
    public function __invoke(Request $request, LocationContext $context): RedirectResponse
    {
        abort_unless(config('locations.enabled'), 404);

        $id = (int) $request->validate(['location_id' => ['required', 'integer']])['location_id'];

        abort_unless($context->forUser($request->user())['locations']->contains('id', $id), 403);

        $request->session()->put('ext_location_id', $id);

        // Only return to a root-relative path on this app, never to an external Referer.
        $referer = parse_url((string) $request->headers->get('referer'));
        $origin = parse_url(config('app.url'));
        $path = is_array($referer) ? ($referer['path'] ?? '/') : '/';
        $sameOrigin = is_array($referer) && is_array($origin)
            && ($referer['scheme'] ?? null) === ($origin['scheme'] ?? null)
            && ($referer['host'] ?? null) === ($origin['host'] ?? null)
            && ($referer['port'] ?? null) === ($origin['port'] ?? null);
        $safePath = str_starts_with($path, '/') && ! str_starts_with($path, '//') && ! str_contains($path, '\\');

        return redirect($sameOrigin && $safePath
            ? $path.(isset($referer['query']) ? '?'.$referer['query'] : '')
            : route('home'));
    }
}

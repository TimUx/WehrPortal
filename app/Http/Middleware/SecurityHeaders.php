<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every response: no MIME sniffing (stored uploads are served with their own
 * type), no framing by other sites (except the public calendar, which Wehren embed on their
 * website), a strict Referrer-Policy, only the browser features the app uses, HSTS over HTTPS and a
 * Content-Security-Policy. The CSP is report-only for now: it shows violations in the browser
 * console without blocking anything, until it has proven itself on real instances.
 */
class SecurityHeaders
{
    /** Pages other websites may show in an iframe */
    private const EMBEDDABLE = ['kalender.public'];

    public const CSP = "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' data: blob: https://tile.openstreetmap.org https://*.tile.openstreetmap.org; font-src 'self' data:; "
        ."connect-src 'self'; worker-src 'self'; manifest-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // Camera for scanning and photos, location for maps and the Einsatzstelle; nothing else
        $headers->set('Permissions-Policy', 'camera=(self), geolocation=(self), microphone=(), payment=(), usb=(), interest-cohort=()');
        if (! $request->routeIs(...self::EMBEDDABLE)) {
            $headers->set('X-Frame-Options', 'SAMEORIGIN');
            if (! $headers->has('Content-Security-Policy') && ! $headers->has('Content-Security-Policy-Report-Only')) {
                $headers->set('Content-Security-Policy-Report-Only', self::CSP);
            }
        }
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}

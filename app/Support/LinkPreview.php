<?php

namespace App\Support;

use App\Services\ModuleRegistry;
use App\Services\OrganizationSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * What WhatsApp, Signal & Co. show for a shared link: the module's name and description instead of
 * "Anmelden". Link previews are fetched without login, so the login page and the preview response
 * for crawlers both use the page the link points to.
 */
class LinkPreview
{
    /** User agents of link preview fetchers (messengers, social networks) */
    private const CRAWLERS = '/WhatsApp|facebookexternalhit|Facebot|TelegramBot|Twitterbot|Slackbot|Discordbot|LinkedInBot|SkypeUriPreview|Signal|Viber|Threema|Iframely|Embedly|redditbot|Pinterest|Mastodon|vkShare|Applebot|Googlebot|bingbot|bot\b|preview/i';

    public function __construct(private readonly ModuleRegistry $modules, private readonly OrganizationSettings $org) {}

    public static function isCrawler(?string $userAgent): bool
    {
        return filled($userAgent) && (bool) preg_match(self::CRAWLERS, $userAgent);
    }

    /** @return array{title: string, description: string, image: string, url: string, site: string} */
    public function forRequest(Request $request): array
    {
        // The login page stands in for the page the visitor wanted to see
        if ($request->routeIs('login', 'auth.magic-link.form') && ($intended = $request->session()->get('url.intended'))) {
            return $this->forUrl($intended);
        }

        return $this->build($request->route()?->getName(), $request->fullUrl());
    }

    public function forUrl(string $url): array
    {
        try {
            $name = Route::getRoutes()->match(Request::create($url))->getName();
        } catch (Throwable) {
            $name = null;
        }

        return $this->build($name, $url);
    }

    private function build(?string $routeName, string $url): array
    {
        $site = $this->org->name();
        $module = collect($this->modules->all())->first(fn ($def, $key) => $routeName && str_starts_with($routeName, $key.'.'));

        return [
            'title' => $module ? $module['name'].' – '.$this->org->shortName() : $site,
            'description' => $module['description'] ?? 'Die App der '.$site.': Fahrzeuge buchen, Termine, Mitteilungen und mehr.',
            'image' => $this->org->logoUrl() ?? asset('images/openwehr-logo.svg'),
            'url' => $url,
            'site' => $site,
        ];
    }
}

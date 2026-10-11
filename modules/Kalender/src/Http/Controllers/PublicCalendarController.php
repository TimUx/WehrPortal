<?php

namespace Modules\Kalender\src\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ModuleRegistry;
use App\Services\OrganizationSettings;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Services\IcsExporter;

/**
 * Events marked "öffentlich" for the Wehr's own website: an HTML list to embed as iframe, and an
 * iCal feed. No login; only title, time, place and description are shown, never any names.
 */
class PublicCalendarController extends Controller
{
    public function page()
    {
        return response()->view('kalender::pages.public', [
            'events' => $this->events(),
            'org' => app(OrganizationSettings::class)->name(),
        ])->header('Cache-Control', 'public, max-age=600');
    }

    public function ics(IcsExporter $exporter)
    {
        return response($exporter->render($this->events(), withLinks: false), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="termine.ics"',
            'Cache-Control' => 'public, max-age=900',
        ]);
    }

    private function events()
    {
        abort_unless(app(ModuleRegistry::class)->isActive('kalender'), 404);

        return Event::public()->where('ends_at', '>', now())->where('starts_at', '<', now()->addYear())->orderBy('starts_at')->limit(100)->get();
    }
}

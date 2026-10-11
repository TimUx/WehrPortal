<?php

namespace Modules\Kalender\src\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ModuleRegistry;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Services\FeedService;
use Modules\Kalender\src\Services\IcsExporter;

/** Subscription link for phone calendars: no login, the secret token identifies the Kamerad */
class FeedController extends Controller
{
    public function __invoke(string $token, FeedService $feeds, IcsExporter $exporter)
    {
        $user = $feeds->userFor($token);

        abort_unless($user && $user->is_active && app(ModuleRegistry::class)->isActive('kalender') && $user->canUseModule('kalender'), 404);

        $events = Event::visibleTo($user)->where('ends_at', '>', now()->subDays(90))->where('starts_at', '<', now()->addYear())
            ->orderBy('starts_at')->get();

        return response($exporter->render($events), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="kalender.ics"',
            'Cache-Control' => 'private, max-age=900',
        ]);
    }
}

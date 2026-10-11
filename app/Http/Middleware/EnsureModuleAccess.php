<?php

namespace App\Http\Middleware;

use App\Services\Impersonation;
use App\Services\ModuleOverview;
use App\Services\ModuleRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleAccess
{
    public function __construct(private readonly ModuleRegistry $registry) {}

    /**
     * Abort with 404 when the module is not active.
     * Abort with 403 when the authenticated user lacks access.
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        if (! $this->registry->isActive($module)) {
            abort(404);
        }

        $user = $request->user();

        if ($user && $user->canUseModule($module)) {
            // Page views sort the start page; not Livewire updates, and not an admin looking as the Kamerad
            if ($request->isMethod('GET') && ! $request->hasHeader('X-Livewire') && ! app(Impersonation::class)->active()) {
                app(ModuleOverview::class)->recordUse($user, $module);
            }

            return $next($request);
        }

        abort(403);
    }
}

<?php

namespace Extensions\Dateien\Http\Middleware;

use App\Services\ModuleRegistry;
use Closure;
use Extensions\Dateien\Services\FileAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDateienAccess
{
    public function __construct(
        private readonly FileAccess $access,
        private readonly ModuleRegistry $modules,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->modules->isActive('dateien')) {
            abort(404);
        }

        $user = $request->user();
        if (! $user || (! $user->canUseModule('dateien') && ! $this->access->hasLocationFolderAccess($user))) {
            abort(403);
        }

        return $next($request);
    }
}

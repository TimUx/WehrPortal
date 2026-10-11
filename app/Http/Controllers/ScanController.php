<?php

namespace App\Http\Controllers;

use App\Services\ScanRegistry;

/**
 * Handles /i/{code}: resolves the code via the ScanRegistry and redirects
 * to the owning module's detail page. Public — auth is enforced at the
 * destination (Filament requires login for admin panels).
 */
class ScanController extends Controller
{
    public function __invoke(string $code, ScanRegistry $registry)
    {
        $url = $registry->resolve($code);

        if ($url === null) {
            abort(404);
        }

        return redirect($url);
    }
}

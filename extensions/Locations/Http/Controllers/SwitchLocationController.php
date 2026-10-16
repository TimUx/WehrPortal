<?php

namespace Extensions\Locations\Http\Controllers;

use Extensions\Locations\Services\LocationAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SwitchLocationController
{
    public function __invoke(Request $request, LocationAccess $access): RedirectResponse
    {
        $id = (int) $request->validate(['location_id' => ['required', 'integer']])['location_id'];

        abort_unless($access->canSee($request->user(), $id), 403);

        $request->session()->put('ext_location_id', $id);

        return back();
    }
}

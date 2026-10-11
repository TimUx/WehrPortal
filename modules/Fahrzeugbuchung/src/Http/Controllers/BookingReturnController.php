<?php

namespace Modules\Fahrzeugbuchung\src\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Services\BookingService;

/**
 * "Fahrzeug ist schon zurück" from the reminder mail. The signed link replaces the login;
 * GET only shows a button so mail scanners can't end the booking by opening the link.
 */
class BookingReturnController extends Controller
{
    public function show(Booking $booking): View
    {
        return view('fahrzeugbuchung::bookings.return', ['booking' => $booking->load('vehicle', 'user')]);
    }

    public function store(Request $request, Booking $booking, BookingService $service): RedirectResponse
    {
        if ($booking->isRunning()) {
            $service->endEarly($booking);
            session()->flash('returned', true);
        }

        return redirect($request->fullUrl());
    }
}

<?php

namespace Modules\Fahrzeugbuchung\src\Notifications;

use Carbon\CarbonInterface;
use Modules\Fahrzeugbuchung\src\Models\Booking;

/** Formats bookings for notification mails (Berlin time, German) */
class BookingSummary
{
    public static function line(Booking $booking): string
    {
        $start = $booking->starts_at->setTimezone('Europe/Berlin');
        $end = $booking->ends_at->setTimezone('Europe/Berlin');

        $time = $start->isSameDay($end)
            ? $start->isoFormat('dd., D. MMM YYYY, HH:mm').' – '.$end->format('H:i').' Uhr'
            : $start->isoFormat('dd., D. MMM, HH:mm').' – '.$end->isoFormat('dd., D. MMM YYYY, HH:mm').' Uhr';

        return $booking->vehicle->name.', '.$time.', '.$booking->user->name.' („'.$booking->purpose.'")';
    }

    public static function day(Booking $booking): string
    {
        return $booking->starts_at->setTimezone('Europe/Berlin')->isoFormat('D. MMM YYYY');
    }

    public static function dateTime(?CarbonInterface $date): string
    {
        return $date?->setTimezone('Europe/Berlin')->isoFormat('D. MMM YYYY, HH:mm').' Uhr';
    }
}

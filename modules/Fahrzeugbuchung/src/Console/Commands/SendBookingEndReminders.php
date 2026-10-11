<?php

namespace Modules\Fahrzeugbuchung\src\Console\Commands;

use Illuminate\Console\Command;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Notifications\BookingEndReminderNotification;
use Throwable;

class SendBookingEndReminders extends Command
{
    protected $signature = 'bookings:send-end-reminders';

    protected $description = 'Remind bookers an hour before their booking ends';

    public function handle(): int
    {
        // Bookings of an hour or less would get the reminder at their start, so they are skipped
        $due = Booking::active()
            ->whereNull('end_reminder_sent_at')
            ->where('ends_at', '>', now())
            ->where('ends_at', '<=', now()->addHour())
            ->whereRaw("ends_at - starts_at > interval '1 hour'")
            ->with(['user', 'vehicle'])
            ->get();

        $sent = 0;
        foreach ($due as $booking) {
            // Claim the booking first so a second scheduler run can't send the same reminder
            $claimed = Booking::whereKey($booking->id)
                ->whereNull('end_reminder_sent_at')
                ->update(['end_reminder_sent_at' => now()]);
            if (! $claimed) {
                continue;
            }

            try {
                $booking->user->notify(new BookingEndReminderNotification($booking));
                $sent++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        // Runs every minute, so only report when something was sent
        if ($sent > 0) {
            $this->info("{$sent} Erinnerung(en) verschickt.");
        }

        return self::SUCCESS;
    }
}

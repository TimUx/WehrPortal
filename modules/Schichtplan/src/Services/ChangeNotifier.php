<?php

namespace Modules\Schichtplan\src\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Schichtplan\src\Models\Assignment;
use Modules\Schichtplan\src\Models\Plan;
use Modules\Schichtplan\src\Notifications\ShiftsChangedNotification;
use Throwable;

/**
 * After publishing, the planer keeps moving people around. Once a plan has been left alone for
 * QUIET_MINUTES, everyone whose shifts differ from what they were last sent gets their new shifts
 * (or the news that they are off the plan). Plans with self sign-up are left out: there people
 * change their own places, and changes by others are told right away (SignupService).
 */
class ChangeNotifier
{
    public const QUIET_MINUTES = 10;

    /** @return array<int, list<string>> user id => their shifts as "12.06. 14:00–15:00 Uhr: Grill" */
    public function snapshot(Plan $plan): array
    {
        return Assignment::where('plan_id', $plan->id)->with(['slot', 'station'])->get()
            ->sortBy(fn (Assignment $a) => [$a->slot->starts_at->getTimestamp(), $a->station->name])
            ->groupBy('user_id')
            ->map(fn ($shifts) => $shifts->map(fn (Assignment $a) => self::label($a))->values()->all())
            ->sortKeys()->all();
    }

    public static function label(Assignment $a): string
    {
        return $a->slot->starts_at->copy()->setTimezone('Europe/Berlin')->format('d.m.').' '.$a->slot->label().' Uhr: '.$a->station->name;
    }

    /** The plan was just sent: that is what everyone knows now */
    public function remember(Plan $plan): void
    {
        $plan->forceFill(['notified_snapshot' => $this->snapshot($plan), 'pending_hash' => null, 'pending_since' => null])->saveQuietly();
    }

    public function run(): int
    {
        $sent = 0;
        Plan::where('status', Plan::PUBLISHED)->where('signup', 'planner')->where('ends_at', '>', now())->get()
            ->each(function (Plan $plan) use (&$sent) {
                $sent += $this->check($plan);
            });

        return $sent;
    }

    private function check(Plan $plan): int
    {
        $now = $this->snapshot($plan);
        // Published before change notices existed: start from here
        if ($plan->notified_snapshot === null) {
            $this->remember($plan);

            return 0;
        }
        $before = collect($plan->notified_snapshot)->mapWithKeys(fn ($shifts, $id) => [(int) $id => $shifts])->all();
        if ($before == $now) {
            if ($plan->pending_hash) {
                $plan->forceFill(['pending_hash' => null, 'pending_since' => null])->saveQuietly();
            }

            return 0;
        }

        $hash = hash('sha256', json_encode($now));
        if ($plan->pending_hash !== $hash) {
            $plan->forceFill(['pending_hash' => $hash, 'pending_since' => now()])->saveQuietly();

            return 0;
        }
        if ($plan->pending_since->gt(now()->subMinutes(self::QUIET_MINUTES))) {
            return 0;
        }

        // Claimed first, so a second scheduler doesn't send the same news again
        $claimed = DB::table('sp_plans')->where('id', $plan->id)->where('pending_hash', $hash)
            ->update(['notified_snapshot' => json_encode($now), 'pending_hash' => null, 'pending_since' => null]);
        if ($claimed !== 1) {
            return 0;
        }

        $changed = collect(array_keys($before + $now))->filter(fn (int $id) => ($before[$id] ?? []) !== ($now[$id] ?? []));
        $sent = 0;
        User::whereIn('id', $changed)->active()->get()->each(function (User $user) use ($plan, $now, &$sent) {
            try {
                $user->notify(new ShiftsChangedNotification($plan, $now[$user->id] ?? []));
                $sent++;
            } catch (Throwable $e) {
                report($e);
            }
        });

        return $sent;
    }
}

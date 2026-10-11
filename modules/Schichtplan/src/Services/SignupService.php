<?php

namespace Modules\Schichtplan\src\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Schichtplan\src\Models\Assignment;
use Modules\Schichtplan\src\Models\Plan;
use Modules\Schichtplan\src\Models\Slot;
use Modules\Schichtplan\src\Models\Station;
use Modules\Schichtplan\src\Notifications\ShiftDecidedNotification;
use Modules\Schichtplan\src\Notifications\ShiftReminderNotification;
use Modules\Schichtplan\src\Notifications\ShiftRequestedNotification;
use Modules\Schichtplan\src\Notifications\SignupOpenNotification;
use Throwable;

/**
 * Self sign-up for Arbeitsdienste and Feste: Kameraden of the target group pick their places in a
 * published plan until the deadline, also at the kiosk tablet for someone else. With approval a
 * place stays "angefragt" until the planer or the Stationsverantwortung confirms. Capacity, one
 * place per block, "höchstens am Stück" and the limit per person are checked here; the unique key
 * (slot, user) of the database guards the rest.
 */
class SignupService
{
    /** A Kamerad of the target group with access to the Schichtplan */
    public static function mayJoin(User $user, Plan $plan): bool
    {
        return $user->is_active && $user->canUseModule('schichtplan') && $plan->inAudience($user);
    }

    /** Planer and the Stationsverantwortung of the station decide on requests */
    public static function canApprove(User $user, Assignment $assignment): bool
    {
        return $user->canManageSchichtplan() || Station::whereKey($assignment->station_id)->where('lead_user_id', $user->id)->exists();
    }

    /** Published plans with self sign-up the Kamerad can still join */
    public function openPlansFor(User $user): Collection
    {
        return Plan::where('status', Plan::PUBLISHED)->where('signup', 'self')->where('ends_at', '>', now())->orderBy('starts_at')->get()
            ->filter(fn (Plan $p) => $p->signupOpen() && self::mayJoin($user, $p))->values();
    }

    /** Everyone the plan is open for, e.g. for the invitation */
    public function audience(Plan $plan): Collection
    {
        return User::active()->orderBy('name')->get()->filter(fn (User $u) => self::mayJoin($u, $plan))->values();
    }

    /**
     * $by takes the place for $for (themselves, or someone else at the kiosk). Planer bypass the
     * approval; everyone else gets "angefragt" when the plan asks for it.
     */
    public function signUp(User $by, Plan $plan, Slot $slot, Station $station, ?User $for = null): Assignment
    {
        $for ??= $by;
        $fail = fn (string $message) => throw ValidationException::withMessages(['signup' => $message]);

        if (! $plan->signupOpen()) {
            $fail('Für diesen Plan kann man sich nicht (mehr) eintragen.');
        }
        if ($slot->plan_id !== $plan->id || $station->plan_id !== $plan->id) {
            $fail('Diese Schicht gehört nicht zum Plan.');
        }
        if (! self::mayJoin($by, $plan) && ! $by->canManageSchichtplan()) {
            $fail('Du gehörst nicht zur Zielgruppe dieses Plans.');
        }
        if (! self::mayJoin($for, $plan)) {
            $fail($for->name.' gehört nicht zur Zielgruppe dieses Plans.');
        }
        if ($slot->starts_at->lte(now())) {
            $fail('Diese Schicht hat schon begonnen.');
        }

        $assignment = DB::transaction(function () use ($by, $plan, $slot, $station, $for, $fail) {
            // The station row is the lock: two people can't take its last place at once
            Station::whereKey($station->id)->lockForUpdate()->first();

            $mine = Assignment::where('plan_id', $plan->id)->where('user_id', $for->id)->with('slot')->get();
            if ($mine->contains('slot_id', $slot->id)) {
                $fail($for->name.' ist in diesem Zeitblock schon eingetragen.');
            }
            if (Assignment::where('slot_id', $slot->id)->where('station_id', $station->id)->count() >= $station->capacity) {
                $fail('„'.$station->name.'“ ist in diesem Zeitblock schon voll.');
            }
            if ($plan->max_shifts && $mine->count() >= $plan->max_shifts) {
                $fail('Höchstens '.$plan->max_shifts.' Schicht'.($plan->max_shifts === 1 ? '' : 'en').' je Person.');
            }
            if ($this->runLength($plan, $mine->pluck('slot_id')->push($slot->id)->all(), $slot->id) > $plan->max_consecutive) {
                $fail('Höchstens '.$plan->max_consecutive.' Schicht'.($plan->max_consecutive === 1 ? '' : 'en').' am Stück – bitte eine Pause dazwischen.');
            }

            $plan->participants()->firstOrCreate(['user_id' => $for->id]);

            // Taken by the Kamerad, so "Plan füllen" keeps it (locked)
            return Assignment::create([
                'plan_id' => $plan->id, 'slot_id' => $slot->id, 'station_id' => $station->id, 'user_id' => $for->id, 'locked' => true,
                'status' => $plan->needs_approval && ! $by->canManageSchichtplan() ? Assignment::REQUESTED : Assignment::CONFIRMED,
                'signed_up_by' => $by->id,
            ]);
        });

        if ($assignment->isRequested()) {
            $this->notify($this->approvers($assignment), new ShiftRequestedNotification($assignment));
        }

        return $assignment;
    }

    /** Give a place back until the deadline; a planer or the kiosk can do it for someone else */
    public function withdraw(User $by, Assignment $assignment): void
    {
        $plan = $assignment->plan;
        if (! $by->canManageSchichtplan()) {
            if ($assignment->user_id !== $by->id && ! self::mayJoin($by, $plan)) {
                throw ValidationException::withMessages(['signup' => 'Diese Schicht gehört jemand anderem.']);
            }
            if (! $plan->signupOpen()) {
                throw ValidationException::withMessages(['signup' => 'Die Eintragung ist geschlossen. Bitte beim Planer absagen.']);
            }
        }

        $this->remove($assignment);
    }

    public function approve(User $by, Assignment $assignment): void
    {
        $this->assertApprover($by, $assignment);
        if ($assignment->isRequested()) {
            $assignment->update(['status' => Assignment::CONFIRMED]);
            $this->notify(collect([$assignment->user]), new ShiftDecidedNotification($assignment, true));
        }
    }

    public function reject(User $by, Assignment $assignment, ?string $reason = null): void
    {
        $this->assertApprover($by, $assignment);
        if (! $assignment->isRequested()) {
            return;
        }
        $assignment->load(['plan', 'slot', 'station', 'user']);
        $this->notify(collect([$assignment->user]), new ShiftDecidedNotification($assignment, false, filled($reason) ? trim($reason) : null));
        $this->remove($assignment);
    }

    /** The place goes; without places left the Kamerad is no longer on the plan */
    private function remove(Assignment $assignment): void
    {
        DB::transaction(function () use ($assignment) {
            $assignment->delete();
            if (! Assignment::where('plan_id', $assignment->plan_id)->where('user_id', $assignment->user_id)->exists()) {
                Plan::find($assignment->plan_id)?->participants()->where('user_id', $assignment->user_id)->delete();
            }
        });
    }

    /** Self sign-up plan published: everyone of the target group is invited to pick places */
    public function invite(Plan $plan): int
    {
        $sent = 0;
        foreach ($this->audience($plan) as $user) {
            try {
                $user->notify(new SignupOpenNotification($plan));
                $sent++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }

    /**
     * Hourly: the day before (up to 24 hours ahead) everyone gets one mail with their confirmed
     * shifts. Each place is claimed first, so a second scheduler can't send it again.
     */
    public function remind(): int
    {
        $ids = collect(DB::select(
            "UPDATE sp_assignments a SET reminded_at = now() FROM sp_slots s, sp_plans p
             WHERE a.slot_id = s.id AND a.plan_id = p.id AND p.status = 'published' AND a.status = 'confirmed'
               AND a.reminded_at IS NULL AND s.starts_at > ? AND s.starts_at <= ? RETURNING a.id",
            [now(), now()->addDay()]
        ))->pluck('id');

        Assignment::with(['plan', 'slot', 'station', 'user'])->whereIn('id', $ids)->get()
            ->groupBy(fn (Assignment $a) => $a->user_id.'-'.$a->plan_id)
            ->each(fn (Collection $shifts) => $this->notify(collect([$shifts->first()->user]), new ShiftReminderNotification($shifts->first()->plan, $shifts)));

        return $ids->count();
    }

    /** Planer and the Stationsverantwortung of the station */
    private function approvers(Assignment $assignment): Collection
    {
        $planners = User::active()->get()->filter(fn (User $u) => $u->canManageSchichtplan() && ! $u->is_admin);
        $lead = Station::find($assignment->station_id)?->lead;
        $people = $planners->push($lead)->filter()->unique('id');

        return $people->isNotEmpty() ? $people : User::active()->where('is_admin', true)->get();
    }

    /** How many blocks in a row the slot would be part of */
    private function runLength(Plan $plan, array $slotIds, int $slotId): int
    {
        $order = $plan->slots()->pluck('id')->flip();
        $taken = collect($slotIds)->map(fn ($id) => $order[$id] ?? null)->filter(fn ($i) => $i !== null)->flip();
        $at = $order[$slotId];
        $run = 1;
        for ($i = $at - 1; $taken->has($i); $i--) {
            $run++;
        }
        for ($i = $at + 1; $taken->has($i); $i++) {
            $run++;
        }

        return $run;
    }

    private function assertApprover(User $by, Assignment $assignment): void
    {
        if (! self::canApprove($by, $assignment)) {
            throw ValidationException::withMessages(['signup' => 'Das entscheiden der Planer oder die Stationsverantwortung.']);
        }
    }

    private function notify(Collection $users, $notification): void
    {
        $users->filter()->each(function (User $user) use ($notification) {
            try {
                $user->notify($notification);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}

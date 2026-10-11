<?php

namespace Modules\Fahrzeugbuchung\src\Services;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\BookingDecision;
use Modules\Fahrzeugbuchung\src\Notifications\BookingReactivatedNotification;
use Modules\Fahrzeugbuchung\src\Notifications\DecisionClosedNotification;
use Modules\Fahrzeugbuchung\src\Notifications\DecisionMadeNotification;
use Modules\Fahrzeugbuchung\src\Notifications\DecisionReopenedNotification;
use Modules\Fahrzeugbuchung\src\Notifications\DecisionRequestedNotification;
use Throwable;

class DecisionService
{
    /**
     * Store a booking that overlaps confirmed bookings as a pending request
     * and ask the deciders to resolve the conflict.
     *
     * With $replaces the request is a change of that booking: it stays untouched
     * until the decision and is cancelled when the change is approved.
     */
    public function request(array $data, User $requester, string $reason, ?Booking $replaces = null): BookingDecision
    {
        // A downtime is no conflict a decider could resolve
        app(BookingService::class)->assertNotBlocked((int) $data['vehicle_id'], Carbon::parse($data['starts_at']), Carbon::parse($data['ends_at']));

        $decision = DB::transaction(function () use ($data, $requester, $reason, $replaces) {
            $conflicts = $this->conflictsFor($data['vehicle_id'], $data['starts_at'], $data['ends_at'], $replaces?->id);

            if ($conflicts->isEmpty()) {
                throw ValidationException::withMessages([
                    'vehicleId' => ['Das Fahrzeug ist in diesem Zeitraum frei – du kannst direkt buchen.'],
                ]);
            }

            $booking = Booking::create([
                'vehicle_id' => $data['vehicle_id'],
                'user_id' => $requester->id,
                'group_id' => $data['group_id'] ?? null,
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'purpose' => $data['purpose'],
                'destination' => $data['destination'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => Booking::STATUS_PENDING,
            ]);

            $decision = new BookingDecision([
                'booking_id' => $booking->id,
                'replaces_booking_id' => $replaces?->id,
                'conflicting_booking_ids' => $conflicts->pluck('id')->all(),
                'reason' => $reason,
                'notified_user_ids' => $this->deciders()->pluck('id')->all(),
            ]);
            $decision->addHistory('requested', $requester, $reason);
            $decision->save();

            return $decision;
        });

        $this->notify(User::whereIn('id', $decision->notified_user_ids)->get(), new DecisionRequestedNotification($decision));

        return $decision;
    }

    /**
     * Approve: the request gets the vehicle and every confirmed booking it overlaps is cancelled.
     * Reject: the existing bookings stay and the request is rejected.
     */
    public function decide(BookingDecision $decision, User $decider, bool $approve, ?string $note = null): BookingDecision
    {
        DB::transaction(function () use ($decision, $decider, $approve, $note) {
            $locked = $this->lock($decision);

            if (! $locked->isPending()) {
                throw ValidationException::withMessages([
                    'decision' => ['Hier wurde bereits entschieden.'],
                ]);
            }

            $this->applyOutcome($locked, $approve);
            $this->markDecided($locked, $decider, $approve, $note);
            $locked->addHistory($approve ? 'approved' : 'rejected', $decider, $note);
            $locked->save();
        });

        $decision->refresh();
        $this->releaseReplacedBooking($decision);
        $this->notifyOutcome($decision, $decider, changed: false);

        return $decision;
    }

    /** Flip a decided outcome (approved <-> rejected) */
    public function change(BookingDecision $decision, User $decider, ?string $note = null): BookingDecision
    {
        DB::transaction(function () use ($decision, $decider, $note) {
            $locked = $this->lock($decision);
            $this->ensureCanRevise($locked);

            $approve = $locked->status === BookingDecision::STATUS_REJECTED;
            $this->revertOutcome($locked);
            $this->applyOutcome($locked, $approve);
            $this->markDecided($locked, $decider, $approve, $note);
            $locked->addHistory($approve ? 'changed_approved' : 'changed_rejected', $decider, $note);
            $locked->save();
        });

        $decision->refresh();
        $this->releaseReplacedBooking($decision);
        $this->notifyOutcome($decision, $decider, changed: true);

        return $decision;
    }

    /** Undo the decision: everything goes back to how it was while the request was open */
    public function reopen(BookingDecision $decision, User $decider, ?string $note = null): BookingDecision
    {
        DB::transaction(function () use ($decision, $decider, $note) {
            $locked = $this->lock($decision);
            $this->ensureCanRevise($locked);

            $this->revertOutcome($locked);
            $locked->fill([
                'status' => BookingDecision::STATUS_PENDING,
                'decided_by' => null,
                'decided_at' => null,
                'decision_note' => null,
            ]);
            $locked->addHistory('reopened', $decider, $note);
            $locked->save();
        });

        $decision->refresh();
        $this->notify($this->involvedUsers($decision), new DecisionReopenedNotification($decision, $decider, $note));

        return $decision;
    }

    /** Called when the requester cancels their pending request */
    public function withdraw(BookingDecision $decision): void
    {
        if (! $decision->isPending()) {
            return;
        }

        $decision->status = BookingDecision::STATUS_WITHDRAWN;
        $decision->decided_at = now();
        $decision->addHistory('withdrawn', $decision->booking->user);
        $decision->save();

        $this->notify(
            User::whereIn('id', $decision->notified_user_ids ?? [])->get(),
            new DecisionClosedNotification($decision)
        );
    }

    /**
     * A booking that won a decision was cancelled or changed: give the slot back
     * to the booking that lost, as long as it is still ahead and nothing else took the slot.
     *
     * Approved decisions: the request won, the cancelled conflicts lost.
     * Rejected decisions: the conflicts won, the rejected request lost.
     */
    public function releaseSlotsFreedBy(Booking $winner): void
    {
        $winner->refresh();

        $asRequest = BookingDecision::where('booking_id', $winner->id)
            ->where('status', BookingDecision::STATUS_APPROVED)
            ->get();
        $asExisting = BookingDecision::whereJsonContains('conflicting_booking_ids', $winner->id)
            ->where('status', BookingDecision::STATUS_REJECTED)
            ->get();

        foreach ($asRequest as $decision) {
            foreach ($decision->conflictingBookings() as $loser) {
                if ($loser->isCancelled()) {
                    $this->reactivate($decision, $loser, $winner, fn () => $loser->update(['cancelled_at' => null]));
                }
            }
        }

        foreach ($asExisting as $decision) {
            $loser = $decision->booking;
            if ($loser->isRejected() && ! $loser->isCancelled()) {
                $this->reactivate($decision, $loser, $winner, fn () => $loser->update(['status' => Booking::STATUS_CONFIRMED]));
            }
        }
    }

    /** Active deciders; falls back to admins so a request never goes unanswered */
    public function deciders(): Collection
    {
        $deciders = User::active()
            ->whereHas('moduleAccess', fn ($q) => $q
                ->where('module', 'fahrzeugbuchung')
                ->where('role', 'entscheider'))
            ->get();

        return $deciders->isNotEmpty() ? $deciders : User::active()->where('is_admin', true)->get();
    }

    private function applyOutcome(BookingDecision $decision, bool $approve): void
    {
        $request = $decision->booking;

        if (! $approve) {
            $request->update(['status' => Booking::STATUS_REJECTED]);

            return;
        }

        // Re-check: bookings may have been added or cancelled since the request
        $conflicts = $this->conflictsFor($request->vehicle_id, $request->starts_at, $request->ends_at, $decision->replaces_booking_id);
        $conflicts->each->update(['cancelled_at' => now()]);

        $replaced = $decision->replacedBooking;
        if ($replaced && ! $replaced->isCancelled()) {
            $replaced->update(['cancelled_at' => now()]);
        }

        $this->restore(fn () => $request->update(['status' => Booking::STATUS_CONFIRMED]), $request);

        // Record what this decision actually cancelled, so it can be undone precisely
        $decision->conflicting_booking_ids = $conflicts->pluck('id')->all();
    }

    private function revertOutcome(BookingDecision $decision): void
    {
        $request = $decision->booking;
        $wasApproved = $decision->status === BookingDecision::STATUS_APPROVED;

        // The request stops blocking first, so the old bookings can take their slot back
        $request->update(['status' => Booking::STATUS_PENDING]);

        if (! $wasApproved) {
            return;
        }

        foreach ($decision->conflictingBookings() as $booking) {
            if ($booking->isCancelled()) {
                $this->restore(fn () => $booking->update(['cancelled_at' => null]), $booking);
            }
        }

        $replaced = $decision->replacedBooking;
        if ($replaced && $replaced->isCancelled()) {
            $this->restore(fn () => $replaced->update(['cancelled_at' => null]), $replaced);
        }
    }

    private function markDecided(BookingDecision $decision, User $decider, bool $approve, ?string $note): void
    {
        $decision->fill([
            'status' => $approve ? BookingDecision::STATUS_APPROVED : BookingDecision::STATUS_REJECTED,
            'decided_by' => $decider->id,
            'decided_at' => now(),
            'decision_note' => $note ?: null,
        ]);
    }

    private function ensureCanRevise(BookingDecision $decision): void
    {
        if (! $decision->isDecided()) {
            throw ValidationException::withMessages([
                'decision' => ['Diese Entscheidung kann nicht mehr geändert werden.'],
            ]);
        }

        if ($decision->booking->isCancelled()) {
            throw ValidationException::withMessages([
                'decision' => ['Die angefragte Buchung wurde inzwischen storniert – hier gibt es nichts mehr zu entscheiden.'],
            ]);
        }
    }

    /** Run a change that makes a booking block its slot again; explain instead of failing on an overlap */
    private function restore(callable $change, Booking $booking): void
    {
        try {
            DB::transaction($change);
        } catch (QueryException $e) {
            if ($e->getCode() !== '23P01' && ! str_contains($e->getMessage(), 'bookings_no_overlap')) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'decision' => [sprintf(
                    'Nicht möglich: %s („%s") kann nicht wiederhergestellt werden, weil das Fahrzeug in diesem Zeitraum inzwischen anderweitig gebucht ist.',
                    $booking->vehicle->name,
                    $booking->purpose,
                )],
            ]);
        }
    }

    private function reactivate(BookingDecision $decision, Booking $loser, Booking $winner, callable $change): void
    {
        if ($loser->ends_at->isPast()) {
            return;
        }

        $blocked = $this->conflictsFor($loser->vehicle_id, $loser->starts_at, $loser->ends_at, $loser->id)->isNotEmpty();
        if ($blocked) {
            return;
        }

        $change();

        $decision->addHistory('reactivated', null, sprintf(
            '„%s" von %s, weil „%s" von %s %s wurde.',
            $loser->purpose,
            $loser->user->name,
            $winner->purpose,
            $winner->user->name,
            $winner->isCancelled() ? 'storniert' : 'geändert',
        ));
        $decision->save();

        $this->notify($this->involvedUsers($decision), new BookingReactivatedNotification($decision, $loser->fresh(), $winner));
    }

    /** An approved change replaces the requester's own booking; that booking may have won an earlier decision */
    private function releaseReplacedBooking(BookingDecision $decision): void
    {
        if ($decision->status === BookingDecision::STATUS_APPROVED && $decision->replacedBooking) {
            $this->releaseSlotsFreedBy($decision->replacedBooking);
        }
    }

    private function notifyOutcome(BookingDecision $decision, User $decider, bool $changed): void
    {
        $affected = $decision->conflictingBookings()->pluck('user')
            ->push($decision->booking->user)
            ->unique('id');
        $this->notify($affected, new DecisionMadeNotification($decision, $changed));

        $otherDeciders = User::whereIn('id', $decision->notified_user_ids ?? [])
            ->where('id', '!=', $decider->id)
            ->whereNotIn('id', $affected->pluck('id'))
            ->get();
        $this->notify($otherDeciders, new DecisionClosedNotification($decision, $changed));
    }

    /** Requester, owners of the conflicting bookings, the deciders who were asked and whoever decided */
    private function involvedUsers(BookingDecision $decision): Collection
    {
        return $decision->conflictingBookings()->pluck('user')
            ->push($decision->booking->user)
            ->merge(User::whereIn('id', [...($decision->notified_user_ids ?? []), ...array_filter([$decision->decided_by])])->get())
            ->unique('id')
            ->values();
    }

    private function lock(BookingDecision $decision): BookingDecision
    {
        return BookingDecision::whereKey($decision->id)->lockForUpdate()->first();
    }

    private function conflictsFor(int $vehicleId, $startsAt, $endsAt, ?int $excludeBookingId = null): Collection
    {
        return Booking::active()
            ->where('vehicle_id', $vehicleId)
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->when($excludeBookingId, fn ($q) => $q->where('id', '!=', $excludeBookingId))
            ->get();
    }

    /**
     * The decision is already stored when mails go out; a mail server problem
     * must not turn that into an error page. Failures end up in the log.
     */
    private function notify(Collection $users, Notification $notification): void
    {
        foreach ($users as $user) {
            try {
                $user->notify($notification);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}

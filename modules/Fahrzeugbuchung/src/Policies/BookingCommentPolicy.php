<?php

namespace Modules\Fahrzeugbuchung\src\Policies;

use App\Models\User;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\BookingComment;

class BookingCommentPolicy
{
    /** Everyone sees the calendar, so everyone may join the conversation on a booking */
    public function create(User $user, Booking $booking): bool
    {
        return $user->is_active && ! $booking->isCancelled();
    }

    public function update(User $user, BookingComment $comment): bool
    {
        return ! $comment->trashed() && $comment->user_id === $user->id;
    }

    public function delete(User $user, BookingComment $comment): bool
    {
        return $this->update($user, $comment);
    }

    /** Deciders (Wehrführung) and admins also see deleted messages and earlier versions */
    public function viewHistory(User $user): bool
    {
        return $user->canDecide();
    }
}

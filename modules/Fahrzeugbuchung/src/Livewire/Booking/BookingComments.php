<?php

namespace Modules\Fahrzeugbuchung\src\Livewire\Booking;

use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\BookingComment;

/** Conversation on a booking, shown in the booking detail */
class BookingComments extends Component
{
    #[Locked]
    public int $bookingId;

    public string $body = '';

    public ?int $editingId = null;

    public string $editBody = '';

    public function send(): void
    {
        $booking = Booking::findOrFail($this->bookingId);
        $this->authorize('create', [BookingComment::class, $booking]);

        $this->validate(['body' => ['required', 'string', 'max:2000']], [], ['body' => 'Nachricht']);

        $booking->comments()->create([
            'user_id' => auth()->id(),
            'body' => trim($this->body),
        ]);

        $this->reset('body');
    }

    public function startEdit(int $commentId): void
    {
        $comment = $this->comment($commentId);
        $this->authorize('update', $comment);

        $this->editingId = $comment->id;
        $this->editBody = $comment->body;
        $this->resetValidation();
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'editBody');
    }

    public function saveEdit(): void
    {
        $comment = $this->comment($this->editingId);
        $this->authorize('update', $comment);

        $this->validate(['editBody' => ['required', 'string', 'max:2000']], [], ['editBody' => 'Nachricht']);

        $comment->edit(trim($this->editBody));

        $this->cancelEdit();
    }

    public function delete(int $commentId): void
    {
        $comment = $this->comment($commentId);
        $this->authorize('delete', $comment);

        $comment->delete();

        if ($this->editingId === $commentId) {
            $this->cancelEdit();
        }
    }

    private function comment(?int $commentId): BookingComment
    {
        return BookingComment::where('booking_id', $this->bookingId)->findOrFail($commentId);
    }

    public function render()
    {
        $booking = Booking::findOrFail($this->bookingId);
        $seesHistory = auth()->user()->can('viewHistory', BookingComment::class);

        $comments = $booking->comments()
            ->when($seesHistory, fn ($query) => $query->withTrashed()->with('revisions'))
            ->with('user')
            ->get();

        return view('fahrzeugbuchung::livewire.booking.booking-comments', [
            'comments' => $comments,
            'seesHistory' => $seesHistory,
            'canWrite' => auth()->user()->can('create', [BookingComment::class, $booking]),
        ]);
    }
}

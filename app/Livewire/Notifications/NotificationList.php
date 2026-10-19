<?php

namespace App\Livewire\Notifications;

use App\Services\Impersonation;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/** All own notifications, newest first; unread ones stand out */
class NotificationList extends Component
{
    use WithPagination;

    public bool $onlyUnread = false;

    public function updatedOnlyUnread(): void
    {
        $this->resetPage();
    }

    public function markAllRead(): void
    {
        if (! app(Impersonation::class)->active()) {
            auth()->user()->unreadNotifications()->update(['read_at' => now()]);
        }
        // The bell and the list on /benachrichtigungen show the same entries: both redraw
        $this->dispatch('notifications-read');
    }

    /** Another component marked entries read: redraw so dots and count agree */
    #[On('notifications-read')]
    public function refreshEntries(): void {}

    public function render()
    {
        $query = $this->onlyUnread ? auth()->user()->unreadNotifications() : auth()->user()->notifications();

        return view('livewire.notifications.notification-list', [
            'notifications' => $query->paginate(25),
            'unread' => auth()->user()->unreadNotifications()->count(),
            'viewingAs' => app(Impersonation::class)->active(),
        ]);
    }
}

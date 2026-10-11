<?php

namespace App\Livewire\Notifications;

use App\Services\Impersonation;
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
    }

    public function render()
    {
        $query = $this->onlyUnread ? auth()->user()->unreadNotifications() : auth()->user()->notifications();

        return view('livewire.notifications.notification-list', [
            'notifications' => $query->paginate(25),
            'unread' => auth()->user()->unreadNotifications()->count(),
        ]);
    }
}

<?php

namespace App\Livewire\Notifications;

use App\Services\Impersonation;
use Livewire\Attributes\On;
use Livewire\Component;

/** The bell next to "Profil": unread count and the latest entries; mails are sent as before */
class Bell extends Component
{
    public const LATEST = 8;

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
        $user = auth()->user();

        return view('livewire.notifications.bell', [
            'unread' => $user->unreadNotifications()->count(),
            'latest' => $user->notifications()->limit(self::LATEST)->get(),
            'viewingAs' => app(Impersonation::class)->active(),
        ]);
    }
}

<?php

namespace App\Livewire\Notifications;

use App\Services\Impersonation;
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
    }

    public function render()
    {
        $user = auth()->user();

        return view('livewire.notifications.bell', [
            'unread' => $user->unreadNotifications()->count(),
            'latest' => $user->notifications()->limit(self::LATEST)->get(),
        ]);
    }
}

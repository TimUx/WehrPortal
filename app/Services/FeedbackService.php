<?php

namespace App\Services;

use App\Models\FeedbackThread;
use App\Models\User;
use App\Notifications\FeedbackAnsweredNotification;
use App\Notifications\FeedbackReceivedNotification;
use Illuminate\Support\Facades\DB;
use Throwable;

class FeedbackService
{
    public function start(User $user, string $subject, string $body, ?string $moduleKey = null): FeedbackThread
    {
        $thread = DB::transaction(function () use ($user, $subject, $body, $moduleKey) {
            $thread = FeedbackThread::create([
                'user_id' => $user->id,
                'subject' => $subject,
                'module_key' => $moduleKey,
                'unread_for_admin' => true,
                'last_message_at' => now(),
            ]);
            $thread->messages()->create(['user_id' => $user->id, 'from_admin' => false, 'body' => $body]);

            return $thread;
        });

        $this->notifyAdmins($thread, isNew: true);

        return $thread;
    }

    /** A reply from the member reopens a closed thread; a reply from an admin goes to the member */
    public function reply(FeedbackThread $thread, User $author, string $body, bool $fromAdmin): void
    {
        $thread->messages()->create(['user_id' => $author->id, 'from_admin' => $fromAdmin, 'body' => $body]);

        $thread->update([
            'last_message_at' => now(),
            'status' => $fromAdmin ? $thread->status : FeedbackThread::STATUS_OPEN,
            'unread_for_user' => $fromAdmin,
            'unread_for_admin' => ! $fromAdmin,
        ]);

        if ($fromAdmin) {
            $this->send($thread->user, new FeedbackAnsweredNotification($thread, $author, $body));
        } else {
            $this->notifyAdmins($thread, isNew: false);
        }
    }

    public function setStatus(FeedbackThread $thread, string $status): void
    {
        $thread->update(['status' => $status]);
    }

    private function notifyAdmins(FeedbackThread $thread, bool $isNew): void
    {
        $message = $thread->messages()->latest('id')->first();

        User::active()->where('is_admin', true)->get()
            ->each(fn (User $admin) => $this->send($admin, new FeedbackReceivedNotification($thread, $message->body, $isNew)));
    }

    /** The message is stored either way; a mail server problem ends up in the log */
    private function send(User $user, $notification): void
    {
        try {
            $user->notify($notification);
        } catch (Throwable $e) {
            report($e);
        }
    }
}

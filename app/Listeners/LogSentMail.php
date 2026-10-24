<?php

namespace App\Listeners;

use App\Models\MailLog;
use App\Models\User;
use App\Notifications\EmailChangeConfirmNotification;
use App\Notifications\InvitationNotification;
use App\Notifications\MagicLinkNotification;
use App\Services\ActivityRecorder;
use Illuminate\Mail\Events\MessageSent;
use Modules\Fahrzeugbuchung\src\Notifications\BookingEndReminderNotification;
use Modules\Kalender\src\Notifications\GuardianCopyNotification;
use Symfony\Component\Mime\Address;
use Throwable;

/** Writes every sent e-mail to the mail log; a failure here never breaks sending */
class LogSentMail
{
    /** Mails with login or confirmation links: logged without content, never sent again */
    // Mails with a link that logs in or acts without login: stored without their content
    public const SENSITIVE = [MagicLinkNotification::class, EmailChangeConfirmNotification::class, InvitationNotification::class,
        BookingEndReminderNotification::class, GuardianCopyNotification::class];

    public const RESEND_HEADER = 'X-WehrPortal-Resend-Of';

    public function handle(MessageSent $event): void
    {
        try {
            $message = $event->sent->getOriginalMessage();
            $type = $event->data['__laravel_notification'] ?? ($event->data['__laravel_mailable'] ?? null);
            $resentOf = $message->getHeaders()->get(self::RESEND_HEADER)?->getBodyAsString();
            $redacted = in_array($type, self::SENSITIVE, true);
            $to = collect($message->getTo())->map(fn (Address $a) => $a->getAddress());

            $log = MailLog::create([
                'sent_at' => now(),
                'to' => $to->implode(', '),
                'user_id' => User::whereIn('email', $to->all())->value('id'),
                'subject' => mb_substr((string) $message->getSubject(), 0, 500),
                'type' => $resentOf ? 'resend' : $type,
                'html' => $redacted ? null : $message->getHtmlBody(),
                'text' => $redacted ? null : $message->getTextBody(),
                'redacted' => $redacted,
                'resent_from_id' => $resentOf ? (int) $resentOf : null,
                'resent_by' => $resentOf ? auth()->id() : null,
            ]);

            app(ActivityRecorder::class)->record('mail', MailLog::class, $log->id, 'An '.$log->to.': '.$log->subject, mailLogId: $log->id);
        } catch (Throwable $e) {
            report($e);
        }
    }
}

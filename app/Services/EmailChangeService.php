<?php

namespace App\Services;

use App\Models\EmailChangeRequest;
use App\Models\LoginToken;
use App\Models\User;
use App\Notifications\EmailChangeConfirmNotification;
use App\Notifications\EmailChangedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A Kamerad changing their own e-mail address: the new address only becomes the login (magic link)
 * address once its owner confirmed a link sent there (double opt-in). Admins change addresses directly.
 */
class EmailChangeService
{
    /** Asks for the change and mails the confirmation link to the NEW address; the login address stays as it is */
    public function request(User $user, string $newEmail): EmailChangeRequest
    {
        $this->assertAvailable($newEmail, $user);

        $limitKey = 'email-change:'.$user->id;
        if (RateLimiter::tooManyAttempts($limitKey, config('magic-link.email_change_rate_limit', 5))) {
            throw ValidationException::withMessages(['email' => 'Zu viele Anfragen. Bitte versuche es später noch einmal.']);
        }
        RateLimiter::hit($limitKey, 3600);

        $rawToken = Str::random(64);

        $request = EmailChangeRequest::updateOrCreate(['user_id' => $user->id], [
            'current_email' => $user->email,
            'new_email' => $newEmail,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addHours(config('magic-link.email_change_ttl_hours', 24)),
            'created_at' => now(),
        ]);

        // The request is stored either way; a mail server problem ends up in the log
        try {
            Notification::route('mail', $newEmail)->notify(new EmailChangeConfirmNotification($user, $rawToken));
        } catch (Throwable $e) {
            report($e);
        }

        return $request;
    }

    /** The pending request a link belongs to, or null if unknown or expired */
    public function find(string $token): ?EmailChangeRequest
    {
        $request = EmailChangeRequest::with('user')->where('token_hash', hash('sha256', $token))->first();

        return $request && ! $request->isExpired() ? $request : null;
    }

    /** Confirms the link: the new address becomes the login address */
    public function confirm(string $token): User
    {
        $request = $this->find($token)
            ?? throw ValidationException::withMessages(['token' => 'Dieser Link ist ungültig oder abgelaufen. Beantrage die Änderung im Profil noch einmal.']);

        $user = $request->user;

        // An admin changed the address in the meantime: the request is outdated
        if ($user->email !== $request->current_email) {
            $request->delete();
            throw ValidationException::withMessages(['token' => 'Die E-Mail-Adresse wurde inzwischen von einem Admin geändert. Beantrage die Änderung im Profil noch einmal.']);
        }

        $this->assertAvailable($request->new_email, $user, 'token');

        $oldEmail = $user->email;

        DB::transaction(function () use ($user, $request) {
            $user->update(['email' => $request->new_email]);
            $request->delete();
            // Login links already sent to the old address must not work any more
            LoginToken::where('user_id', $user->id)->whereNull('used_at')->delete();
        });

        try {
            Notification::route('mail', $oldEmail)->notify(new EmailChangedNotification($user->refresh()));
        } catch (Throwable $e) {
            report($e);
        }

        return $user;
    }

    public function cancel(User $user): void
    {
        EmailChangeRequest::where('user_id', $user->id)->delete();
    }

    public function pending(User $user): ?EmailChangeRequest
    {
        $request = EmailChangeRequest::where('user_id', $user->id)->first();

        return $request && ! $request->isExpired() ? $request : null;
    }

    private function assertAvailable(string $email, User $user, string $field = 'email'): void
    {
        if (User::whereRaw('lower(email) = ?', [mb_strtolower($email)])->whereKeyNot($user->id)->exists()) {
            throw ValidationException::withMessages([$field => 'Diese E-Mail-Adresse wird schon von jemand anderem verwendet.']);
        }
    }
}

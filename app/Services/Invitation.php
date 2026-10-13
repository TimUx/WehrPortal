<?php

namespace App\Services;

use App\Models\LoginToken;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Einladung for a (new) Kamerad: a login link that is valid for some days and only once – as QR code to
 * scan in the Wache or by mail. It is an ordinary magic link (GET shows a button, the POST logs in), so
 * mail scanners can't use it up; the first login leads to the Willkommen page.
 */
class Invitation
{
    public const DAYS = 7;

    /** @return array{url: string, expires_at: Carbon} */
    public function create(User $member): array
    {
        if (! $member->is_active) {
            throw ValidationException::withMessages(['invite' => 'Nur aktive Kameraden lassen sich einladen.']);
        }
        $raw = Str::random(64);
        $expires = now()->addDays(self::DAYS);
        LoginToken::create(['user_id' => $member->id, 'token_hash' => hash('sha256', $raw), 'expires_at' => $expires, 'created_at' => now()]);

        return ['url' => route('auth.magic-link.confirm', $raw), 'expires_at' => $expires];
    }

    public function send(User $member, User $by): Carbon
    {
        $invite = $this->create($member);
        $member->notify(new InvitationNotification($invite['url'], $invite['expires_at'], $by->name));

        return $invite['expires_at'];
    }
}

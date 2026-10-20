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
        if (! $member->hasLogin()) {
            throw ValidationException::withMessages(['invite' => $member->name.' ist ohne E-Mail-Adresse angelegt und hat keinen Login. Für eine Einladung zuerst eine Adresse eintragen.']);
        }
        $raw = Str::random(64);
        $expires = now()->addDays(self::DAYS);
        LoginToken::create(['user_id' => $member->id, 'token_hash' => hash('sha256', $raw), 'expires_at' => $expires, 'created_at' => now()]);

        return ['url' => route('auth.magic-link.confirm', $raw), 'expires_at' => $expires];
    }

    /**
     * The link to show on screen (QR code, copy): whoever sees it can log in as $member. So only an
     * admin may show it for anyone; a Personalverwalter only for a Kamerad who has never logged in and
     * is no admin – everyone else gets the Einladung by mail to their own address (send()).
     *
     * @return array{url: string, expires_at: Carbon}
     */
    public function createForScreen(User $member, User $by): array
    {
        if (! $by->is_admin && ($member->is_admin || $member->last_login_at !== null)) {
            throw ValidationException::withMessages(['invite' => 'Wer sich schon angemeldet hat (oder Admin ist), bekommt die Einladung nur per Mail an die eigene Adresse.']);
        }

        return $this->create($member);
    }

    public function send(User $member, User $by): Carbon
    {
        $invite = $this->create($member);
        $member->notify(new InvitationNotification($invite['url'], $invite['expires_at'], $by->name));

        return $invite['expires_at'];
    }
}

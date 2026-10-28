<?php

namespace App\Services;

use App\Models\LoginToken;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Extensions\Oidc\Support\OidcSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Invitation for a (new) Kamerad: an SSO link when OIDC is active, otherwise a one-time magic link.
 */
class Invitation
{
    public const DAYS = 7;

    /** @return array{url: string, expires_at: ?Carbon, method: 'sso'|'magic_link'} */
    public function create(User $member): array
    {
        if (! $member->is_active) {
            throw ValidationException::withMessages(['invite' => 'Nur aktive Kameraden lassen sich einladen.']);
        }
        if (! $member->hasLogin()) {
            throw ValidationException::withMessages(['invite' => $member->name.' ist ohne E-Mail-Adresse angelegt und hat keinen Login. Für eine Einladung zuerst eine Adresse eintragen.']);
        }

        if (app(OidcSettings::class)->isActive()) {
            return [
                'url' => route('oidc.redirect', ['login_hint' => $member->email]),
                'expires_at' => null,
                'method' => 'sso',
            ];
        }

        $raw = Str::random(64);
        $expires = now()->addDays(self::DAYS);
        LoginToken::create(['user_id' => $member->id, 'token_hash' => hash('sha256', $raw), 'expires_at' => $expires, 'created_at' => now()]);

        return ['url' => route('auth.magic-link.confirm', $raw), 'expires_at' => $expires, 'method' => 'magic_link'];
    }

    /**
     * The link to show on screen (QR code, copy): whoever sees it can log in as $member. So only an
     * admin may show it for anyone; a Personalverwalter only for a Kamerad who has never logged in and
     * is no admin – everyone else gets the Einladung by mail to their own address (send()).
     *
     * @return array{url: string, expires_at: ?Carbon, method: 'sso'|'magic_link'}
     */
    public function createForScreen(User $member, User $by): array
    {
        if (! $by->is_admin && ($member->is_admin || $member->last_login_at !== null)) {
            throw ValidationException::withMessages(['invite' => 'Wer sich schon angemeldet hat (oder Admin ist), bekommt die Einladung nur per Mail an die eigene Adresse.']);
        }

        return $this->create($member);
    }

    public function send(User $member, User $by): ?Carbon
    {
        $invite = $this->create($member);
        $member->notify(new InvitationNotification($invite['url'], $invite['expires_at'], $by->name, $invite['method'] === 'sso'));

        return $invite['expires_at'];
    }
}

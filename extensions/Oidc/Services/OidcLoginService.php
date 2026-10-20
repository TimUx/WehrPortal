<?php

namespace Extensions\Oidc\Services;

use App\Models\User;
use App\Services\ActivityRecorder;
use Extensions\Oidc\Models\Identity;
use Extensions\Oidc\OidcException;
use Extensions\Oidc\Support\OidcSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Maps a verified ID token to a local user: primarily by issuer + subject; on the first login
 * optionally by verified e-mail address; optionally by creating the account (JIT). Admin rights
 * are never derived from claims, and inactive users never get in.
 */
class OidcLoginService
{
    public function __construct(private readonly OidcSettings $settings) {}

    /** @param array<string, mixed> $claims */
    public function login(array $claims, Request $request): User
    {
        $user = $this->resolve($claims);

        if (! $user->is_active) {
            throw $this->deny('inactive', 'user_inactive');
        }
        // Members without an e-mail address have no login, also not through a linked identity
        if (! $user->hasLogin()) {
            throw $this->deny('no_account', 'user_without_login');
        }

        if ($user->last_login_at === null && $request->hasSession()) {
            $request->session()->put('first_login', true);
        }

        $user->update(['last_login_at' => now()]);
        Identity::where('user_id', $user->id)->where('issuer', (string) $this->settings->issuer())->update(['last_login_at' => now()]);
        // The method is logged, no claims or tokens
        app(ActivityRecorder::class)->record('login', User::class, $user->id, $user->name, ['method' => 'oidc'], userId: $user->id);

        Auth::login($user, remember: true);

        return $user;
    }

    /** @param array<string, mixed> $claims */
    private function resolve(array $claims): User
    {
        $issuer = (string) $this->settings->issuer();
        $subject = (string) $claims['sub'];

        $identity = Identity::with('user')->where('issuer', $issuer)->where('subject', $subject)->first();
        if ($identity?->user) {
            return $identity->user;
        }

        $email = is_string($claims['email'] ?? null) ? trim($claims['email']) : '';
        $verified = ($claims['email_verified'] ?? false) === true || ($claims['email_verified'] ?? null) === 'true';

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw $this->deny('no_account', 'email_missing');
        }
        if (! $verified) {
            throw $this->deny('unverified', 'email_unverified');
        }

        $user = $this->settings->linkByEmail()
            ? User::whereRaw('lower(email) = ?', [mb_strtolower($email)])->first()
            : null;

        if ($user) {
            if (! $user->is_active) {
                throw $this->deny('inactive', 'user_inactive');
            }
            // An account already tied to another identity of this IdP is never re-linked by e-mail
            if (Identity::where('user_id', $user->id)->where('issuer', $issuer)->exists()) {
                throw $this->deny('no_account', 'already_linked');
            }

            return $this->link($user, $issuer, $subject);
        }

        if ($this->settings->jit() && ! User::whereRaw('lower(email) = ?', [mb_strtolower($email)])->exists()) {
            return DB::transaction(function () use ($claims, $email, $issuer, $subject) {
                // No admin rights, no module access: everything beyond the basics is granted by hand
                $user = new User(['name' => $this->displayName($claims, $email), 'email' => $email]);
                $user->is_admin = false;
                $user->save();

                return $this->link($user, $issuer, $subject);
            });
        }

        throw $this->deny('no_account', 'no_account');
    }

    private function link(User $user, string $issuer, string $subject): User
    {
        Identity::create(['user_id' => $user->id, 'issuer' => $issuer, 'subject' => $subject]);

        return $user;
    }

    /** @param array<string, mixed> $claims */
    private function displayName(array $claims, string $email): string
    {
        foreach (['name', 'preferred_username'] as $claim) {
            if (is_string($claims[$claim] ?? null) && trim($claims[$claim]) !== '') {
                return mb_substr(trim($claims[$claim]), 0, 255);
            }
        }

        return mb_substr(strstr($email, '@', true) ?: $email, 0, 255);
    }

    private function deny(string $message, string $reason): OidcException
    {
        return OidcException::of($message, $reason);
    }

    public static function logFailure(OidcException $e): void
    {
        Log::warning('OIDC login denied', ['reason' => $e->reason]);
    }
}

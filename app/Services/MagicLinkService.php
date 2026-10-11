<?php

namespace App\Services;

use App\Models\LoginToken;
use App\Models\User;
use App\Notifications\MagicLinkNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MagicLinkService
{
    public function sendLink(string $email, Request $request): void
    {
        $this->checkRateLimits($email, $request->ip());

        $user = User::where('email', $email)->where('is_active', true)->first();

        // Always show the same response regardless of whether the email exists
        // (prevents user enumeration)
        if (! $user) {
            return;
        }

        $rawToken = Str::random(64);
        $tokenHash = hash('sha256', $rawToken);

        LoginToken::create([
            'user_id' => $user->id,
            'token_hash' => $tokenHash,
            'expires_at' => now()->addMinutes(config('magic-link.ttl_minutes', 15)),
            'created_at' => now(),
        ]);

        $user->notify(new MagicLinkNotification($rawToken));
    }

    public function consumeToken(string $rawToken, Request $request): User
    {
        $tokenHash = hash('sha256', $rawToken);

        $loginToken = LoginToken::where('token_hash', $tokenHash)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $loginToken) {
            throw ValidationException::withMessages([
                'token' => ['Dieser Link ist ungültig oder abgelaufen. Bitte fordere einen neuen Link an.'],
            ]);
        }

        $loginToken->update([
            'used_at' => now(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $user = $loginToken->user;

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'token' => ['Dein Konto wurde deaktiviert. Bitte wende dich an den Administrator.'],
            ]);
        }

        $user->update(['last_login_at' => now()]);
        app(ActivityRecorder::class)->record('login', User::class, $user->id, $user->name, userId: $user->id);

        Auth::login($user, remember: true);

        return $user;
    }

    private function checkRateLimits(string $email, ?string $ip): void
    {
        $emailKey = 'magic-link:email:'.hash('sha256', strtolower($email));
        $ipKey = 'magic-link:ip:'.($ip ?? 'unknown');

        $emailLimit = config('magic-link.rate_limit_per_email', 5);
        $ipLimit = config('magic-link.rate_limit_per_ip', 20);

        if (RateLimiter::tooManyAttempts($emailKey, $emailLimit)) {
            $seconds = RateLimiter::availableIn($emailKey);
            throw ValidationException::withMessages([
                'email' => ["Zu viele Anfragen. Bitte warte {$seconds} Sekunden."],
            ]);
        }

        if (RateLimiter::tooManyAttempts($ipKey, $ipLimit)) {
            throw ValidationException::withMessages([
                'email' => ['Zu viele Anfragen von dieser IP-Adresse. Bitte versuche es später erneut.'],
            ]);
        }

        RateLimiter::hit($emailKey, 3600);
        RateLimiter::hit($ipKey, 3600);
    }
}

<?php

namespace Modules\Kalender\src\Services;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Modules\Kalender\src\Models\FeedToken;

/** The personal subscription link (read-only iCal feed of the events meant for the Kamerad) */
class FeedService
{
    public function url(User $user): string
    {
        $token = FeedToken::where('user_id', $user->id)->first();
        $raw = $token ? Crypt::decryptString($token->token_encrypted) : $this->regenerate($user);

        return route('kalender.feed', ['token' => $raw]);
    }

    /** A new link; the old one stops working at once */
    public function regenerate(User $user): string
    {
        $raw = Str::random(48);

        FeedToken::updateOrCreate(['user_id' => $user->id], [
            'token_hash' => hash('sha256', $raw),
            'token_encrypted' => Crypt::encryptString($raw),
        ]);

        return $raw;
    }

    public function userFor(string $raw): ?User
    {
        return FeedToken::where('token_hash', hash('sha256', $raw))->first()?->user;
    }
}

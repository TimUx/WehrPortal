<?php

namespace App\Livewire\Profile;

use App\Services\CredentialRegistry;
use App\Support\QrCode;
use Illuminate\Support\Facades\URL;
use Livewire\Component;

/** Personalakte → Nachweise: the cards, and a QR code that is renewed before it runs out */
class Credentials extends Component
{
    public const VALID_MINUTES = 10;

    public function render(CredentialRegistry $registry)
    {
        $user = auth()->user();
        $url = URL::temporarySignedRoute('credentials.check', now()->addMinutes(self::VALID_MINUTES), ['user' => $user->id]);

        return view('livewire.profile.credentials', [
            'cards' => $registry->cards($user),
            'qr' => QrCode::dataUri($url, 260),
            'until' => now('Europe/Berlin')->addMinutes(self::VALID_MINUTES),
        ]);
    }
}

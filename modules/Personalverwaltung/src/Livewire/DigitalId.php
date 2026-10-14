<?php

namespace Modules\Personalverwaltung\src\Livewire;

use App\Livewire\Profile\Credentials;
use App\Support\QrCode;
use Illuminate\Support\Facades\URL;
use Livewire\Component;
use Modules\Personalverwaltung\src\Services\PersonnelService;

/** The digitaler Dienstausweis with a QR code for checking that is renewed before it runs out */
class DigitalId extends Component
{
    public function render()
    {
        $user = auth()->user();
        $url = URL::temporarySignedRoute('credentials.check', now()->addMinutes(Credentials::VALID_MINUTES), ['user' => $user->id]);

        return view('personalverwaltung::livewire.digital-id', [
            'id' => PersonnelService::digitalIdFor($user),
            'qr' => QrCode::dataUri($url, 260),
            'until' => now('Europe/Berlin')->addMinutes(Credentials::VALID_MINUTES),
        ]);
    }
}

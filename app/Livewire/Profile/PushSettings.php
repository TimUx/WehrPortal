<?php

namespace App\Livewire\Profile;

use App\Models\PushSubscription;
use App\Services\PushService;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/** Profil: push notifications on this device, and the devices that get them */
class PushSettings extends Component
{
    public ?string $flash = null;

    public function subscribe(array $subscription, ?string $userAgent = null): void
    {
        try {
            app(PushService::class)->subscribe(auth()->user(), $subscription, self::device($userAgent));
        } catch (ValidationException $e) {
            $this->addError('push', collect($e->errors())->flatten()->first());
        }
    }

    public function unsubscribe(string $endpoint): void
    {
        app(PushService::class)->unsubscribe(auth()->user(), $endpoint);
    }

    public function remove(int $id): void
    {
        PushSubscription::where('user_id', auth()->id())->whereKey($id)->delete();
        $this->flash = 'Gerät entfernt.';
    }

    public function test(PushService $push): void
    {
        $sent = $push->toUser(auth()->user(), 'Test von WehrPortal', 'So sehen Push-Benachrichtigungen auf diesem Gerät aus.', route('profile'));
        $this->flash = $sent ? 'Test an '.$sent.' '.($sent === 1 ? 'Gerät' : 'Geräte').' geschickt.' : 'Kein Gerät hat die Nachricht angenommen.';
    }

    /** "Chrome auf Android" from the user agent, so the list of devices is readable */
    private static function device(?string $agent): ?string
    {
        if (! $agent) {
            return null;
        }
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge', str_contains($agent, 'Firefox/') => 'Firefox', str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari', default => 'Browser',
        };
        $system = match (true) {
            str_contains($agent, 'Android') => 'Android', (bool) preg_match('/iPhone|iPad|iPod/', $agent) => 'iPhone/iPad',
            str_contains($agent, 'Windows') => 'Windows', str_contains($agent, 'Mac OS') => 'Mac', str_contains($agent, 'Linux') => 'Linux', default => null,
        };

        return $browser.($system ? ' auf '.$system : '');
    }

    public function render(PushService $push)
    {
        return view('livewire.profile.push-settings', [
            'publicKey' => $push->publicKey(),
            'devices' => PushSubscription::where('user_id', auth()->id())->latest()->get(),
        ]);
    }
}

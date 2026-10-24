<?php

use App\Models\Setting;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Services\OrganizationSettings;

test('the default app and offline notification branding is WehrPortal', function () {
    config(['app.name' => 'WehrPortal']);

    $this->get(route('auth.magic-link.form'))
        ->assertOk()
        ->assertSee('WehrPortal');

    $this->get('/sw.js')
        ->assertOk()
        ->assertSee("data.title || 'WehrPortal'", false)
        ->assertSee("'openwehr-shell-v2'", false);
});

test('the rename preserves saved organization branding', function () {
    config(['app.name' => 'WehrPortal']);
    Setting::set('org_name', 'Freiwillige Feuerwehr Musterstadt');
    Setting::set('org_short_name', 'FF Musterstadt');

    expect(app(OrganizationSettings::class)->name())->toBe('Freiwillige Feuerwehr Musterstadt')
        ->and(app(OrganizationSettings::class)->shortName())->toBe('FF Musterstadt');

    $this->get(route('auth.magic-link.form'))
        ->assertOk()
        ->assertSee('FF Musterstadt');
});

test('invitations use WehrPortal without changing the login link', function () {
    $url = route('auth.magic-link.form');
    $notification = new InvitationNotification($url, now()->addDay(), 'Hanna Hydrant');
    $mail = $notification->toMail(User::factory()->create());

    expect(implode(' ', $mail->introLines))->toContain('lädt dich zu WehrPortal ein')
        ->and($mail->actionUrl)->toBe($url);
});

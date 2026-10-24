<?php

use App\Filament\Pages\OrganizationSettingsPage;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Services\OrganizationSettings;
use Livewire\Livewire;

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

test('the app header uses the saved organization identity without recoloring its logo', function () {
    Setting::set('org_name', 'Freiwillige Feuerwehr Musterstadt');
    Setting::set('org_short_name', 'FF Musterstadt');
    $org = app(OrganizationSettings::class);
    $org->saveLogo(base64_encode('test-image'), 'image/webp');

    $this->actingAs(User::factory()->admin()->create())->get('/')
        ->assertOk()->assertSee('FF Musterstadt')->assertSee($org->logoUrl())
        ->assertDontSee('brightness-0 invert', false);
    $this->get('/admin/einstellungen')->assertOk()->assertSee($org->logoUrl());

    // The installable app remains recognizable independently of the organization.
    $this->get('/manifest.webmanifest')->assertOk()->assertJsonPath('name', 'WehrPortal')
        ->assertJsonPath('short_name', 'WehrPortal')->assertJsonPath('icons.2.purpose', 'maskable');
});

test('empty organization names fall back to WehrPortal in the header', function () {
    config(['app.name' => 'WehrPortal']);
    Setting::set('org_name', '  ');
    Setting::set('org_short_name', '');
    $org = app(OrganizationSettings::class);
    expect($org->name())->toBe('WehrPortal')->and($org->shortName())->toBe('WehrPortal');
    $this->actingAs(User::factory()->create())->get('/')
        ->assertOk()->assertSee('WehrPortal')->assertSee('images/wehrportal-logo.svg');
});

test('organization names are persisted by the admin form and can be cleared', function () {
    $page = Livewire::actingAs(User::factory()->admin()->create())->test(OrganizationSettingsPage::class)
        ->set('org_name', 'Freiwillige Feuerwehr Musterstadt')->set('org_short_name', 'FF Musterstadt')
        ->call('save')->assertHasNoErrors();
    expect(app(OrganizationSettings::class)->shortName())->toBe('FF Musterstadt');
    $page->set('org_name', '')->set('org_short_name', '')->call('save')->assertHasNoErrors();
    expect(app(OrganizationSettings::class)->shortName())->toBe(config('app.name'));
});

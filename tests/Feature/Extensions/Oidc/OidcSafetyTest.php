<?php

use App\Models\ActivityLog;
use App\Models\LoginToken;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\MagicLinkNotification;
use App\Services\AuthMethod;
use App\Services\AuthMethodRegistry;
use App\Services\MagicLinkService;
use Extensions\Oidc\Filament\OidcSettingsPage;
use Extensions\Oidc\Services\OidcClient;
use Extensions\Oidc\Support\OidcSettings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/helpers.php';

function requestMagicLink(User $user): void
{
    app(MagicLinkService::class)->sendLink($user->email, request());
}

// ─── Core without the extension ───────────────────────────────────────────

test('the core registry without a method changes nothing', function () {
    $registry = new AuthMethodRegistry;
    $user = User::factory()->create();
    $admin = User::factory()->admin()->create();

    expect($registry->enabled())->toBe([])
        ->and($registry->magicLinkRestricted())->toBeFalse()
        ->and($registry->allowsMagicLink($user))->toBeTrue()
        ->and($registry->isLocalAdmin($admin))->toBeTrue()
        ->and($registry->isLocalAdmin($user))->toBeFalse();
});

test('a registered exclusive method restricts the magic link to local admins', function () {
    $registry = new AuthMethodRegistry;
    $registry->register(new class implements AuthMethod
    {
        public function key(): string
        {
            return 'test';
        }

        public function label(): string
        {
            return 'Test';
        }

        public function loginUrl(): string
        {
            return '/test';
        }

        public function isEnabled(): bool
        {
            return true;
        }

        public function isExclusive(): bool
        {
            return true;
        }
    });

    expect($registry->allowsMagicLink(User::factory()->create()))->toBeFalse()
        ->and($registry->allowsMagicLink(User::factory()->admin()->create()))->toBeTrue()
        ->and($registry->allowsMagicLink(User::factory()->admin()->inactive()->create()))->toBeFalse();
});

test('the magic link login page is unchanged while OIDC is not set up', function () {
    $this->get('/anmelden')->assertOk()->assertSee('Login-Link anfordern')->assertDontSee('Single Sign-On');

    Notification::fake();
    $user = User::factory()->create();
    requestMagicLink($user);
    Notification::assertSentTo($user, MagicLinkNotification::class);
});

test('the login page lists the OIDC method next to the magic link', function () {
    oidcConfigure(['label' => 'Mit Keycloak anmelden']);

    $this->get('/anmelden')->assertOk()->assertSee('Login-Link anfordern')->assertSee('Mit Keycloak anmelden')->assertSee('/auth/oidc/redirect');
});

// ─── Disabled / unconfigured ──────────────────────────────────────────────

test('disabled or unconfigured OIDC has no routes and no button', function () {
    $this->get('/auth/oidc/redirect')->assertNotFound();
    $this->get('/auth/oidc/callback')->assertNotFound();

    $settings = oidcConfigure(['enabled' => false]);
    $this->get('/auth/oidc/redirect')->assertNotFound();
    $this->get('/anmelden')->assertDontSee('/auth/oidc/redirect');

    $settings->set('enabled', true);
    $settings->saveSecret(null);
    expect($settings->isActive())->toBeFalse();
    $this->get('/auth/oidc/redirect')->assertNotFound();
});

test('OIDC_FORCE_DISABLED turns the IdP off and gives the magic link back to everyone', function () {
    oidcConfigure(['exclusive' => true]);
    $user = User::factory()->create();
    expect(app(AuthMethodRegistry::class)->allowsMagicLink($user))->toBeFalse();

    config(['oidc.force_disabled' => true]);

    $this->get('/auth/oidc/redirect')->assertNotFound();
    $this->get('/anmelden')->assertSee('Login-Link anfordern')->assertDontSee('/auth/oidc/redirect');
    expect(app(AuthMethodRegistry::class)->allowsMagicLink($user))->toBeTrue();
});

test('the extension can be switched off by configuration', function () {
    expect(config('oidc.enabled'))->toBeTrue();
    config(['oidc.enabled' => false]);
    oidcConfigure();

    expect(app(OidcSettings::class)->isActive())->toBeFalse();
    $this->get('/auth/oidc/redirect')->assertNotFound();
});

// ─── Local system admin and lockout ───────────────────────────────────────

test('in IdP-only mode only local admins get a magic link', function () {
    oidcConfigure(['exclusive' => true]);
    Notification::fake();
    $local = oidcLocalAdmin();
    $normal = User::factory()->create();
    $otherAdmin = User::factory()->admin()->create();
    $otherAdmin->forceFill(['ext_local_admin' => false])->save();

    requestMagicLink($local);
    requestMagicLink($normal);
    requestMagicLink($otherAdmin);

    Notification::assertSentTo($local, MagicLinkNotification::class);
    Notification::assertNotSentTo($normal, MagicLinkNotification::class);
    Notification::assertNotSentTo($otherAdmin, MagicLinkNotification::class);
});

test('an issued magic link stops working for normal users once IdP-only is on', function () {
    $normal = User::factory()->create();
    LoginToken::create(['user_id' => $normal->id, 'token_hash' => hash('sha256', 'raw-token'), 'expires_at' => now()->addMinutes(5), 'created_at' => now()]);
    oidcConfigure(['exclusive' => true]);

    expect(fn () => app(MagicLinkService::class)->consumeToken('raw-token', request()))->toThrow(ValidationException::class);
    $this->assertGuest();
});

test('the login page hides the magic link form in IdP-only mode but the local page keeps it', function () {
    oidcConfigure(['exclusive' => true]);

    $this->get('/anmelden')->assertOk()->assertDontSee('Login-Link anfordern')->assertSee('/auth/oidc/redirect');
    $this->get('/anmelden/lokal')->assertOk()->assertSee('Login-Link anfordern');
});

test('new admins are local system admins by default', function () {
    expect(User::factory()->admin()->create()->fresh()->ext_local_admin)->toBeTrue()
        ->and(User::factory()->create()->fresh()->ext_local_admin)->toBeFalse();
});

test('the last local admin cannot be removed while IdP-only is on', function () {
    oidcConfigure(['exclusive' => true]);
    $last = oidcLocalAdmin();
    $last->save();

    expect(fn () => $last->delete())->toThrow(ValidationException::class)
        ->and(fn () => $last->forceFill(['is_active' => false])->save())->toThrow(ValidationException::class);
    $last->refresh();
    expect(fn () => $last->forceFill(['is_admin' => false])->save())->toThrow(ValidationException::class);
    $last->refresh();
    expect(fn () => $last->forceFill(['ext_local_admin' => false])->save())->toThrow(ValidationException::class);

    expect(User::find($last->id))->not->toBeNull()->and(User::find($last->id)->is_active)->toBeTrue();
});

test('another local admin makes removing one possible, and without IdP-only nothing is blocked', function () {
    oidcConfigure(['exclusive' => true]);
    $first = User::factory()->admin()->create();
    $second = User::factory()->admin()->create();

    $first->delete();
    expect(User::find($first->id))->toBeNull();

    expect(fn () => $second->forceFill(['is_active' => false])->save())->toThrow(ValidationException::class);

    app(OidcSettings::class)->set('exclusive', false);
    $second->forceFill(['is_active' => false])->save();
    expect($second->fresh()->is_active)->toBeFalse();
});

// ─── Emergency commands ───────────────────────────────────────────────────

test('oidc:disable switches OIDC off and gives the magic link back', function () {
    oidcConfigure(['exclusive' => true]);

    $this->artisan('oidc:disable')->assertSuccessful();

    expect(app(OidcSettings::class)->enabled())->toBeFalse()->and(app(OidcSettings::class)->exclusive())->toBeFalse();
    expect(app(AuthMethodRegistry::class)->allowsMagicLink(User::factory()->create()))->toBeTrue();
    $this->get('/auth/oidc/redirect')->assertNotFound();
});

test('oidc:make-local-admin marks admins and only grants admin on request', function () {
    $admin = User::factory()->admin()->create(['email' => 'bernd@example.com']);
    $admin->forceFill(['ext_local_admin' => false])->save();
    $member = User::factory()->create(['email' => 'hanna@example.com']);

    $this->artisan('oidc:make-local-admin', ['email' => 'BERND@example.com'])->assertSuccessful();
    expect($admin->fresh()->ext_local_admin)->toBeTrue();

    $this->artisan('oidc:make-local-admin', ['email' => 'hanna@example.com'])->assertFailed();
    expect($member->fresh()->is_admin)->toBeFalse();

    $this->artisan('oidc:make-local-admin', ['email' => 'hanna@example.com', '--grant-admin' => true])->assertSuccessful();
    expect($member->fresh()->is_admin)->toBeTrue()->and($member->fresh()->ext_local_admin)->toBeTrue();

    $this->artisan('oidc:make-local-admin', ['email' => 'nobody@example.com'])->assertFailed();
});

// ─── Secret handling ──────────────────────────────────────────────────────

test('the client secret is stored encrypted and never exposed', function () {
    $settings = oidcConfigure();

    $stored = Setting::where('key', 'ext_oidc_client_secret')->value('value');
    expect($stored)->not->toContain('s3cr3t-value')->and(Crypt::decryptString($stored))->toBe('s3cr3t-value')
        ->and($settings->secret())->toBe('s3cr3t-value');
    expect(json_encode(ActivityLog::all()->toArray()))->not->toContain('s3cr3t-value')->not->toContain($stored);

    $this->actingAs(User::factory()->admin()->create());
    $this->get('/admin/oidc')->assertOk()->assertDontSee('s3cr3t-value')->assertDontSee($stored, false)->assertSee('gespeichert');
});

// ─── Admin page ───────────────────────────────────────────────────────────

test('only admins can open the OIDC admin page', function () {
    $this->actingAs(User::factory()->create())->get('/admin/oidc')->assertForbidden();
    auth()->logout();
    $this->actingAs(User::factory()->admin()->create())->get('/admin/oidc')->assertOk()->assertSee(route('oidc.callback'));
});

test('the admin page saves the configuration and encrypts the secret', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test(OidcSettingsPage::class)
        ->fillForm([
            'enabled' => true, 'label' => 'Mit Keycloak anmelden', 'issuer' => OIDC_ISSUER.'/', 'client_id' => 'openwehr',
            'client_secret' => 'typed-secret', 'scopes' => 'openid email', 'link_by_email' => true, 'jit' => false,
            'exclusive' => false, 'local_admins' => [$admin->id],
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertSet('data.client_secret', null);

    $settings = app(OidcSettings::class);
    expect($settings->issuer())->toBe(OIDC_ISSUER)->and($settings->secret())->toBe('typed-secret')->and($settings->isActive())->toBeTrue()
        ->and(Setting::where('key', 'ext_oidc_client_secret')->value('value'))->not->toContain('typed-secret');
});

test('IdP-only cannot be saved without an active local admin', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test(OidcSettingsPage::class)
        ->fillForm([
            'enabled' => true, 'issuer' => OIDC_ISSUER, 'client_id' => 'openwehr', 'client_secret' => 'typed-secret',
            'exclusive' => true, 'local_admins' => [],
        ])
        ->call('save')
        ->assertHasErrors();

    expect(app(OidcSettings::class)->exclusive())->toBeFalse()->and($admin->fresh()->ext_local_admin)->toBeTrue();
});

test('enabling needs issuer, client id and secret', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(OidcSettingsPage::class)->fillForm(['enabled' => true, 'issuer' => '', 'client_id' => ''])->call('save')->assertHasErrors();

    expect(app(OidcSettings::class)->enabled())->toBeFalse();
});

test('the connection test checks discovery and the key set', function () {
    $this->actingAs(User::factory()->admin()->create());
    oidcIdp();

    Livewire::test(OidcSettingsPage::class)->fillForm(['issuer' => OIDC_ISSUER])->call('testConnection')->assertNotified('Verbindung erfolgreich');

    Http::fake(['https://other.example.com/realms/x/.well-known/openid-configuration' => Http::response(['issuer' => 'https://third.example.com'])]);
    Livewire::test(OidcSettingsPage::class)->fillForm(['issuer' => 'https://other.example.com/realms/x'])->call('testConnection')->assertNotified('Verbindung fehlgeschlagen');
});

test('discovery with a foreign issuer or an insecure issuer is refused', function () {
    $client = app(OidcClient::class);

    expect($client->test('http://sso.example.com/realms/x')['ok'])->toBeFalse();
    expect($client->test(null)['ok'])->toBeFalse();
});

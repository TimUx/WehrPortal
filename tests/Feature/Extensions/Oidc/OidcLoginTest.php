<?php

use App\Models\ActivityLog;
use App\Models\User;
use Extensions\Oidc\Models\Identity;
use Jose\Component\KeyManagement\JWKFactory;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    oidcConfigure();
    $this->idp = oidcIdp();
});

test('discovery, PKCE redirect and a successful callback log the user in', function () {
    $user = User::factory()->create(['email' => 'hanna@example.com']);

    $redirect = $this->get('/auth/oidc/redirect');
    $redirect->assertRedirectContains(OIDC_ISSUER.'/protocol/openid-connect/auth?');
    parse_str((string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);

    expect($query['response_type'])->toBe('code')
        ->and($query['client_id'])->toBe('openwehr')
        ->and($query['redirect_uri'])->toBe(route('oidc.callback'))
        ->and($query['scope'])->toBe('openid email profile')
        ->and($query['code_challenge_method'])->toBe('S256');
    $verifier = session('oidc.flow.verifier');
    expect($query['code_challenge'])->toBe(rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='));

    $this->idp->nonce = $query['nonce'];
    $this->get('/auth/oidc/callback?code=abc&state='.$query['state'])->assertRedirect(route('welcome'));

    $this->assertAuthenticatedAs($user);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/token')
        && $request['code_verifier'] === $verifier && $request['code'] === 'abc' && $request['grant_type'] === 'authorization_code');
    expect(Identity::where('issuer', OIDC_ISSUER)->where('subject', 'subject-1')->value('user_id'))->toBe($user->id);

    $log = ActivityLog::where('event', 'login')->latest('id')->first();
    expect($log->user_id)->toBe($user->id)->and($log->changes)->toBe(['method' => 'oidc']);
    expect(json_encode(ActivityLog::all()->toArray()))->not->toContain('s3cr3t')->not->toContain('subject-1');
});

test('the identity (issuer + subject) wins over the e-mail address afterwards', function () {
    $user = User::factory()->create(['email' => 'hanna@example.com']);
    Identity::create(['user_id' => $user->id, 'issuer' => OIDC_ISSUER, 'subject' => 'subject-1']);
    $this->idp->claims = ['email' => 'somebody-else@example.com', 'email_verified' => false];

    oidcFlow($this->idp)->assertRedirect();

    $this->assertAuthenticatedAs($user);
});

test('an invalid state is rejected', function () {
    User::factory()->create(['email' => 'hanna@example.com']);

    oidcFlow($this->idp, ['state' => 'forged'])->assertRedirect(route('auth.magic-link.form'))->assertSessionHasErrors('auth');

    $this->assertGuest();
});

test('a callback without a started flow is rejected', function () {
    $this->get('/auth/oidc/callback?code=abc&state=x')->assertRedirect(route('auth.magic-link.form'))->assertSessionHasErrors('auth');
    $this->assertGuest();
});

test('an error from the IdP is rejected', function () {
    $this->get('/auth/oidc/redirect');
    $this->get('/auth/oidc/callback?error=access_denied')->assertSessionHasErrors('auth');
    $this->assertGuest();
});

test('invalid tokens are rejected', function (array $claims) {
    User::factory()->create(['email' => 'hanna@example.com']);
    $this->idp->claims = $claims;

    oidcFlow($this->idp)->assertRedirect(route('auth.magic-link.form'))->assertSessionHasErrors('auth');

    $this->assertGuest();
    expect(Identity::count())->toBe(0);
})->with([
    'wrong nonce' => [['nonce' => 'other']],
    'missing nonce' => [['nonce' => '__unset__']],
    'wrong audience' => [['aud' => 'another-client']],
    'wrong issuer' => [['iss' => 'https://evil.example.com/realms/x']],
    'expired' => [['exp' => time() - 3600]],
    'missing subject' => [['sub' => '__unset__']],
    'several audiences without azp' => [['aud' => ['openwehr', 'other']]],
]);

test('a token signed with a foreign key is rejected', function () {
    User::factory()->create(['email' => 'hanna@example.com']);
    $this->idp->signWith = JWKFactory::createRSAKey(2048, ['alg' => 'RS256', 'use' => 'sig', 'kid' => 'k1']);

    oidcFlow($this->idp)->assertSessionHasErrors('auth');

    $this->assertGuest();
});

test('a failing token endpoint is rejected', function () {
    User::factory()->create(['email' => 'hanna@example.com']);
    $this->idp->tokenStatus = 400;

    oidcFlow($this->idp)->assertSessionHasErrors('auth');

    $this->assertGuest();
});

test('an unverified e-mail address is rejected', function () {
    User::factory()->create(['email' => 'hanna@example.com']);
    $this->idp->claims = ['email_verified' => false];

    oidcFlow($this->idp)->assertSessionHasErrors('auth');

    $this->assertGuest();
    expect(Identity::count())->toBe(0);
});

test('inactive users are denied, by e-mail and by identity', function () {
    $user = User::factory()->inactive()->create(['email' => 'hanna@example.com']);

    oidcFlow($this->idp)->assertSessionHasErrors('auth');
    $this->assertGuest();

    Identity::create(['user_id' => $user->id, 'issuer' => OIDC_ISSUER, 'subject' => 'subject-1']);
    oidcFlow($this->idp)->assertSessionHasErrors('auth');
    $this->assertGuest();
});

test('members without an e-mail address are denied even with a linked identity', function () {
    $user = User::factory()->create(['email' => null]);
    Identity::create(['user_id' => $user->id, 'issuer' => OIDC_ISSUER, 'subject' => 'subject-1']);

    oidcFlow($this->idp)->assertSessionHasErrors('auth');
    $this->assertGuest();
});

test('unknown users are not created by default', function () {
    oidcFlow($this->idp)->assertSessionHasErrors('auth');

    $this->assertGuest();
    expect(User::where('email', 'hanna@example.com')->exists())->toBeFalse();
});

test('linking by e-mail can be switched off', function () {
    oidcConfigure(['link_by_email' => false]);
    User::factory()->create(['email' => 'hanna@example.com']);

    oidcFlow($this->idp)->assertSessionHasErrors('auth');

    $this->assertGuest();
});

test('an account tied to another identity of the IdP is not re-linked by e-mail', function () {
    $user = User::factory()->create(['email' => 'hanna@example.com']);
    Identity::create(['user_id' => $user->id, 'issuer' => OIDC_ISSUER, 'subject' => 'subject-original']);

    oidcFlow($this->idp)->assertSessionHasErrors('auth');

    $this->assertGuest();
});

test('just-in-time creation is opt-in and creates a plain account', function () {
    oidcConfigure(['jit' => true]);
    $this->idp->claims = ['is_admin' => true, 'admin' => true, 'groups' => ['admin', 'is_admin'], 'roles' => ['admin']];

    oidcFlow($this->idp)->assertRedirect();

    $user = User::where('email', 'hanna@example.com')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    expect($user->name)->toBe('Hanna Hydrant')->and($user->is_admin)->toBeFalse()->and($user->is_active)->toBeTrue()
        ->and($user->moduleAccess()->count())->toBe(0);
});

test('admin rights never come from claims and are not granted on linking', function () {
    $user = User::factory()->create(['email' => 'hanna@example.com']);
    $this->idp->claims = ['is_admin' => true, 'groups' => ['admin']];

    oidcFlow($this->idp);

    expect($user->fresh()->is_admin)->toBeFalse();
});

test('a callback cannot be replayed', function () {
    User::factory()->create(['email' => 'hanna@example.com']);
    $redirect = $this->get('/auth/oidc/redirect');
    parse_str((string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);
    $this->idp->nonce = $query['nonce'];

    $this->get('/auth/oidc/callback?code=abc&state='.$query['state']);
    auth()->logout();
    $this->get('/auth/oidc/callback?code=abc&state='.$query['state'])->assertSessionHasErrors('auth');

    $this->assertGuest();
});

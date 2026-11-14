<?php

use App\Filament\InitialsAvatarProvider;
use App\Http\Middleware\EnsureModuleAccess;
use App\Http\Middleware\EnsureUserIsActive;
use App\Models\Module;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Notifications\MagicLinkNotification;
use App\Services\Invitation;
use App\Support\Csv;
use App\Support\FileResponse;
use App\Support\SafeUrl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('every response carries the security headers; only the public calendar may be framed', function () {
    $response = $this->get(route('auth.magic-link.form'))->assertOk();
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($response->headers->get('Content-Security-Policy-Report-Only'))->toContain("frame-ancestors 'self'");

    Module::where('key', 'kalender')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    expect($this->get(route('kalender.public'))->headers->get('X-Frame-Options'))->toBeNull();
});

test('uploads are served with a fixed type: HTML or script in a file never runs on the app', function () {
    $txt = FileResponse::make('<html><script>alert(1)</script>', 'info.txt');
    expect($txt->headers->get('Content-Type'))->toBe('text/plain; charset=utf-8')
        ->and($txt->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($txt->headers->get('Content-Security-Policy'))->toContain('sandbox');
    expect(FileResponse::make('x', 'evil.html')->headers->get('Content-Type'))->toBe('application/octet-stream');
    $pdf = FileResponse::make('%PDF', 'Plan "Süd".pdf');
    expect($pdf->headers->get('Content-Disposition'))->toStartWith('inline')->toContain("filename*=utf-8''Plan%20%22S%C3%BCd%22.pdf");
});

test('CSV exports neutralise formulas but keep numbers', function () {
    expect(Csv::cell('=HYPERLINK("https://evil")'))->toBe('\'=HYPERLINK("https://evil")')
        ->and(Csv::cell('@SUM(A1)'))->toBe("'@SUM(A1)")
        ->and(Csv::cell('-12,50'))->toBe('-12,50')
        ->and(Csv::cell('+49 123'))->toBe("'+49 123")
        ->and(Csv::cell('Hanna Hydrant'))->toBe('Hanna Hydrant')
        ->and(Csv::cell(42))->toBe(42);
});

test('an Einladung link shown on screen only for Kameraden who never logged in, unless an admin creates it', function () {
    $pv = User::factory()->create();
    $admin = User::factory()->admin()->create(['last_login_at' => now()]);
    $fresh = User::factory()->create(['last_login_at' => null]);
    $used = User::factory()->create(['last_login_at' => now()]);
    $invitation = app(Invitation::class);

    expect($invitation->createForScreen($fresh, $pv)['url'])->toContain('/anmelden/');
    expect(fn () => $invitation->createForScreen($used, $pv))->toThrow(ValidationException::class);
    expect(fn () => $invitation->createForScreen($admin, $pv))->toThrow(ValidationException::class);
    expect($invitation->createForScreen($used, $admin)['url'])->toContain('/anmelden/');

    // By mail to the own address it always works
    Notification::fake();
    $invitation->send($used, $pv);
    Notification::assertSentTo($used, InvitationNotification::class);
});

test('login links use APP_URL, not a forwarded host', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'hanna@example.com']);
    $this->withHeaders(['X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Proto' => 'https'])
        ->post(route('auth.magic-link.send'), ['email' => 'hanna@example.com']);

    Notification::assertSentTo($user, MagicLinkNotification::class, function ($n) use ($user) {
        $url = $n->toMail($user)->actionUrl;

        return parse_url($url, PHP_URL_HOST) === parse_url(config('app.url'), PHP_URL_HOST) && ! str_contains($url, 'evil.example');
    });
});

test('a Kamerad who left can not keep acting through Livewire', function () {
    Module::where('key', 'maengelmanagement')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $user = User::factory()->create();
    $user->moduleAccess()->create(['module' => 'maengelmanagement']);
    $persistent = Livewire::getPersistentMiddleware();

    expect($persistent)->toContain(EnsureUserIsActive::class)->toContain(EnsureModuleAccess::class);
});

test('a made-up X-Forwarded-For does not get a visitor past the login limit', function () {
    Notification::fake();

    // Straight from the internet: the header is ignored, all requests count for one address
    foreach (range(1, 21) as $i) {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '93.184.216.34'])
            ->withHeaders(['X-Forwarded-For' => "198.51.100.{$i}"])
            ->post(route('auth.magic-link.send'), ['email' => "niemand{$i}@example.com"]);
    }
    $response->assertSessionHasErrors('email');

    // Through the reverse proxy on the same host: the proxy's word counts
    $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.1'])
        ->withHeaders(['X-Forwarded-For' => '198.51.100.99'])
        ->post(route('auth.magic-link.send'), ['email' => 'jemand@example.com'])
        ->assertSessionHasNoErrors();
});

test('outbound requests refuse addresses that only look public', function (string $url) {
    expect(fn () => SafeUrl::check($url))->toThrow(RuntimeException::class);
})->with([
    'carrier-grade NAT' => 'http://100.64.0.1/',
    'protocol assignments' => 'http://192.0.0.8/',
    'benchmark network' => 'http://198.18.0.1/',
    'IPv4 inside IPv6' => 'http://[::ffff:127.0.0.1]/',
    'NAT64 to loopback' => 'http://[64:ff9b::7f00:1]/',
    '6to4 to a private host' => 'http://[2002:c0a8:1::1]/',
]);

test('a public address is still allowed for outbound requests', function () {
    expect(SafeUrl::check('https://93.184.216.34/kalender.ics')[2])->toBe('93.184.216.34');
});

test('the policy is enforced once CSP_ENFORCE is on', function () {
    config(['app.csp_enforce' => true]);
    $headers = $this->get(route('auth.magic-link.form'))->assertOk()->headers;

    expect($headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'self'")
        ->and($headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();
});

test('the admin panel draws initials itself instead of asking an avatar service', function () {
    $hanna = User::factory()->create(['name' => 'Hanna Hydrant', 'is_admin' => true]);
    $url = (new InitialsAvatarProvider)->get($hanna);

    expect($url)->toStartWith('data:image/svg+xml;base64,')
        ->and(base64_decode(substr($url, 26)))->toContain('>HH<');
    expect($this->actingAs($hanna)->get('/admin')->getContent())->not->toContain('ui-avatars.com');
});

test('the image takes no visitor address from headers and leaves framing to the app', function () {
    expect(file_get_contents(base_path('Dockerfile')))->toContain('TRUSTED_PROXY=off', 'docker/nginx/security.conf');
    expect(file_get_contents(base_path('docker/nginx/security.conf')))->not->toContain('add_header X-Frame-Options', 'add_header Strict-Transport-Security');
    expect(file_get_contents(base_path('docker-compose.yml')))->toContain('"${APP_BIND:-127.0.0.1}:8080:8080"');
    expect(config('trustedproxy.proxies'))->not->toBe('*');
});

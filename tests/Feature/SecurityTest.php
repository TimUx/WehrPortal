<?php

use App\Http\Middleware\EnsureModuleAccess;
use App\Http\Middleware\EnsureUserIsActive;
use App\Models\Module;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Notifications\MagicLinkNotification;
use App\Services\Invitation;
use App\Support\Csv;
use App\Support\FileResponse;
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

        return str_starts_with($url, rtrim(config('app.url'), '/')) && ! str_contains($url, 'evil.example');
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

<?php

use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Livewire\Auth\LoginForm;
use App\Livewire\Profile\ContactForm;
use App\Models\EmailChangeRequest;
use App\Models\LoginToken;
use App\Models\User;
use App\Notifications\EmailChangeConfirmNotification;
use App\Notifications\EmailChangedNotification;
use App\Notifications\MagicLinkNotification;
use App\Services\EmailChangeService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->member = User::factory()->create(['email' => 'hanna.hydrant@example.com', 'phone' => null]);
    RateLimiter::clear('email-change:'.$this->member->id);

    // Asks for the change and hands back the link token from the mail
    $this->requestChange = function (string $new = 'hanna@example.org') {
        $token = null;
        Notification::fake();
        app(EmailChangeService::class)->request($this->member, $new);
        Notification::assertSentOnDemand(EmailChangeConfirmNotification::class, function ($n) use (&$token) {
            $token = (new ReflectionProperty($n, 'rawToken'))->getValue($n);

            return true;
        });

        return $token;
    };
});

test('the mail link shows a button and confirms only on the second step', function () {
    $token = ($this->requestChange)();

    $this->get(route('auth.email-change.confirm', $token))
        ->assertOk()->assertSee('hanna@example.org')->assertSee('Ja, bestätigen');
    expect($this->member->refresh()->email)->toBe('hanna.hydrant@example.com');

    $this->post(route('auth.email-change.consume', $token))->assertOk()->assertSee('Bestätigt');

    expect($this->member->refresh()->email)->toBe('hanna@example.org');
    expect(EmailChangeRequest::count())->toBe(0);
});

test('the old address is told after the confirmation', function () {
    $token = ($this->requestChange)();
    Notification::fake();

    $this->post(route('auth.email-change.consume', $token));

    Notification::assertSentOnDemand(EmailChangedNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'hanna.hydrant@example.com');
});

test('magic links keep going to the old address until the new one is confirmed', function () {
    ($this->requestChange)();
    Notification::fake();

    Livewire::test(LoginForm::class)->set('email', 'hanna@example.org')->call('submit');
    Notification::assertNothingSent();

    Livewire::test(LoginForm::class)->set('email', 'hanna.hydrant@example.com')->call('submit');
    Notification::assertSentTo($this->member, MagicLinkNotification::class);
});

test('after the confirmation the new address is the login and the old one no longer is', function () {
    $token = ($this->requestChange)();
    $this->post(route('auth.email-change.consume', $token));
    Notification::fake();

    Livewire::test(LoginForm::class)->set('email', 'hanna.hydrant@example.com')->call('submit');
    Notification::assertNothingSent();

    Livewire::test(LoginForm::class)->set('email', 'hanna@example.org')->call('submit');
    Notification::assertSentTo($this->member->refresh(), MagicLinkNotification::class);
});

test('unused login links for the old address stop working', function () {
    LoginToken::factory()->create(['user_id' => $this->member->id, 'used_at' => null]);
    $token = ($this->requestChange)();

    $this->post(route('auth.email-change.consume', $token));

    expect(LoginToken::where('user_id', $this->member->id)->whereNull('used_at')->count())->toBe(0);
});

test('an unknown or expired link is refused', function () {
    $this->get(route('auth.email-change.confirm', 'gibt-es-nicht'))->assertOk()->assertSee('ungültig oder abgelaufen');

    $token = ($this->requestChange)();
    EmailChangeRequest::query()->update(['expires_at' => now()->subMinute()]);

    $this->post(route('auth.email-change.consume', $token))->assertOk()->assertSee('ungültig oder abgelaufen');
    expect($this->member->refresh()->email)->toBe('hanna.hydrant@example.com');
});

test('a link works only once', function () {
    $token = ($this->requestChange)();

    $this->post(route('auth.email-change.consume', $token))->assertSee('Bestätigt');
    $this->post(route('auth.email-change.consume', $token))->assertSee('ungültig oder abgelaufen');
});

test('the address is refused when someone else took it in the meantime', function () {
    $token = ($this->requestChange)();
    User::factory()->create(['email' => 'Hanna@example.org']);

    $this->post(route('auth.email-change.consume', $token))->assertSee('schon von jemand anderem');
    expect($this->member->refresh()->email)->toBe('hanna.hydrant@example.com');
});

test('an admin change in the meantime makes the request outdated', function () {
    $token = ($this->requestChange)();
    $this->member->update(['email' => 'admin-hat-geaendert@example.com']);

    $this->post(route('auth.email-change.consume', $token))->assertSee('von einem Admin geändert');
    expect($this->member->refresh()->email)->toBe('admin-hat-geaendert@example.com');
});

test('a new request replaces the earlier one and the first link no longer works', function () {
    $first = ($this->requestChange)('erste@example.org');
    $second = ($this->requestChange)('zweite@example.org');

    $this->post(route('auth.email-change.consume', $first))->assertSee('ungültig oder abgelaufen');
    $this->post(route('auth.email-change.consume', $second))->assertSee('Bestätigt');
    expect($this->member->refresh()->email)->toBe('zweite@example.org');
});

test('the member can cancel a pending change', function () {
    Notification::fake();

    $component = Livewire::actingAs($this->member)->test(ContactForm::class)->set('email', 'neu@example.org')->call('save');
    expect(EmailChangeRequest::count())->toBe(1);

    $component->call('cancelEmailChange')->assertSet('pendingEmail', null);
    expect(EmailChangeRequest::count())->toBe(0);
});

test('requests are limited per hour', function () {
    Notification::fake();
    $service = app(EmailChangeService::class);

    foreach (range(1, 5) as $i) {
        $service->request($this->member, "neu{$i}@example.org");
    }

    expect(fn () => $service->request($this->member, 'neu6@example.org'))->toThrow(ValidationException::class);
});

test('the tokens are stored hashed', function () {
    $token = ($this->requestChange)();

    expect(EmailChangeRequest::sole()->token_hash)->toBe(hash('sha256', $token))->not->toBe($token);
});

test('an admin changes the address without confirmation', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Notification::fake();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(EditUser::class, ['record' => $this->member->getKey()])
        ->fillForm(['email' => 'direkt@example.org'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->member->refresh()->email)->toBe('direkt@example.org');
    Notification::assertNothingSent();
});

test('only the case of the address may change without confirmation', function () {
    Notification::fake();

    Livewire::actingAs($this->member)
        ->test(ContactForm::class)
        ->set('email', 'Hanna.Hydrant@example.com')
        ->call('save')
        ->assertSet('pendingEmail', null);

    expect($this->member->refresh()->email)->toBe('Hanna.Hydrant@example.com');
    expect(EmailChangeRequest::count())->toBe(0);
});

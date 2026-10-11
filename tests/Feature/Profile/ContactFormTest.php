<?php

use App\Livewire\Profile\ContactForm;
use App\Models\User;
use App\Notifications\EmailChangeConfirmNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->member = User::factory()->create(['email' => 'hanna.hydrant@example.com', 'phone' => null]);
});

test('the profile shows the contact form', function () {
    $this->actingAs($this->member)->get(route('profile'))->assertOk()->assertSeeLivewire(ContactForm::class);
});

test('a new e-mail only counts after confirmation', function () {
    Notification::fake();

    Livewire::actingAs($this->member)
        ->test(ContactForm::class)
        ->assertSet('email', 'hanna.hydrant@example.com')
        ->set('email', '  hanna@example.org ')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('saved', true)
        ->assertSet('pendingEmail', 'hanna@example.org')
        ->assertSet('email', 'hanna.hydrant@example.com');

    // The login address is unchanged until the link was confirmed
    expect($this->member->refresh()->email)->toBe('hanna.hydrant@example.com');
    Notification::assertSentOnDemand(EmailChangeConfirmNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'hanna@example.org');
    Notification::assertNotSentTo($this->member, EmailChangeConfirmNotification::class);
});

test('an unchanged e-mail sends no notice', function () {
    Notification::fake();

    Livewire::actingAs($this->member)->test(ContactForm::class)->call('save')->assertHasNoErrors();

    Notification::assertNothingSent();
});

test('an address of someone else is refused, regardless of case', function () {
    User::factory()->create(['email' => 'Bernd.Blaulicht@example.com']);

    Livewire::actingAs($this->member)
        ->test(ContactForm::class)
        ->set('email', 'bernd.blaulicht@example.com')
        ->call('save')
        ->assertHasErrors('email');

    expect($this->member->refresh()->email)->toBe('hanna.hydrant@example.com');
});

test('invalid input is refused', function () {
    Livewire::actingAs($this->member)
        ->test(ContactForm::class)
        ->set('email', 'keine-adresse')
        ->call('save')
        ->assertHasErrors(['email']);
});

test('a mail problem does not lose the request', function () {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);

    Livewire::actingAs($this->member)
        ->test(ContactForm::class)
        ->set('email', 'neu@example.org')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('pendingEmail', 'neu@example.org');

    expect($this->member->refresh()->email)->toBe('hanna.hydrant@example.com');
});

test('mobile number, birth date and address are not in the profile; stored values stay', function () {
    $this->member->update(['phone' => '04101 12345', 'birth_date' => '1990-05-17', 'show_birthday' => false,
        'street' => 'Dorfstraße 12', 'postal_code' => '23843', 'city' => 'Bad Oldesloe']);

    Livewire::actingAs($this->member)
        ->test(ContactForm::class)
        ->assertDontSee('Mobiltelefon')
        ->assertDontSee('Geburtsdatum')
        ->assertDontSee('Meinen Geburtstag')
        ->assertDontSee('Straße und Hausnummer')
        // Even values sent anyway are not saved
        ->set('phone', '')
        ->set('birthDate', '')
        ->set('showBirthday', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->member->refresh();
    expect($this->member->phone)->toBe('04101 12345')
        ->and($this->member->birth_date->format('Y-m-d'))->toBe('1990-05-17')
        ->and($this->member->show_birthday)->toBeFalse()
        ->and($this->member->street)->toBe('Dorfstraße 12');
});

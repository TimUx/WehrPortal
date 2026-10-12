<?php

use App\Livewire\Notifications\Bell;
use App\Livewire\Notifications\NotificationList;
use App\Models\Module;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\EmailChangeService;
use App\Services\Impersonation;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Modules\Lehrgaenge\src\Models\Course;
use Modules\Lehrgaenge\src\Services\RegistrationService;

beforeEach(function () {
    Module::where('key', 'lehrgaenge')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');

    $this->leader = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $this->leader->moduleAccess()->create(['module' => 'lehrgaenge', 'role' => 'ausbildungsleiter']);
    $this->member = User::factory()->create(['name' => 'Hanna Hydrant']);
    $this->member->moduleAccess()->create(['module' => 'lehrgaenge']);
    $this->course = Course::create(['name' => 'Vegetationsbrandbekämpfung', 'is_standard' => true]);
});

function interest(): void
{
    app(RegistrationService::class)->request(test()->member, test()->course, 'lieber am Wochenende');
}

test('what is mailed also appears under the bell, with the way to where something is to do', function () {
    interest();

    $n = $this->leader->notifications()->sole();
    expect($n->data)->toMatchArray([
        'title' => 'Interesse an Lehrgang: Vegetationsbrandbekämpfung – Hanna Hydrant',
        'body' => 'Hanna Hydrant hat Interesse am Lehrgang Vegetationsbrandbekämpfung gemeldet. Bitte in fox112 Bedarf anmelden.',
        'url' => route('lehrgaenge.registrations'),
        'module' => 'lehrgaenge',
    ])->and($this->member->notifications()->count())->toBe(0);

    Livewire::actingAs($this->leader)->test(Bell::class)
        ->assertSee('1 ungelesen')
        ->assertSee('Interesse an Lehrgang: Vegetationsbrandbekämpfung');

    // Opening it marks it read and leads to the Anmeldungen
    $this->actingAs($this->leader)->get(route('notifications.open', $n->id))->assertRedirect(route('lehrgaenge.registrations'));
    expect($n->fresh()->read_at)->not->toBeNull();
    Livewire::actingAs($this->leader)->test(Bell::class)->assertDontSee('ungelesen)');
});

test('the header shows the bell on every app page', function () {
    $this->actingAs($this->member)->get('/personalakte')->assertOk()->assertSee('Benachrichtigungen');
    $this->get('/benachrichtigungen')->assertOk()->assertSee('Noch keine Benachrichtigungen.');
});

test('only own notifications open, always on this address and never leading away', function () {
    interest();
    $n = $this->leader->notifications()->sole();

    $this->actingAs($this->member)->get(route('notifications.open', $n->id))->assertNotFound();

    // Written by the scheduler under APP_URL, opened under another address: still the right page
    $n->update(['data' => [...$n->data, 'url' => 'https://openwehr.ff-musterstadt.de/lehrgaenge/anmeldungen?status=offen']]);
    $this->actingAs($this->leader)->get(route('notifications.open', $n->id))->assertRedirect('/lehrgaenge/anmeldungen?status=offen');

    $n->update(['data' => [...$n->data, 'url' => 'https://example.com/phishing']]);
    $this->actingAs($this->leader)->get(route('notifications.open', $n->id))->assertRedirect('/phishing');

    $n->update(['data' => [...$n->data, 'url' => 'https://openwehr.ff-musterstadt.de//example.com/phishing']]);
    $this->actingAs($this->leader)->get(route('notifications.open', $n->id))->assertRedirect('/example.com/phishing');

    $n->update(['data' => [...$n->data, 'url' => null]]);
    $this->actingAs($this->leader)->get(route('notifications.open', $n->id))->assertRedirect(route('notifications.index'));
});

test('all can be marked read; the list filters unread ones', function () {
    interest();
    app(RegistrationService::class)->request(User::factory()->create(['name' => 'Tim Tanklösch']), $this->course, null);

    Livewire::actingAs($this->leader)->test(NotificationList::class)
        ->assertSee('Hanna Hydrant')->assertSee('Tim Tanklösch')
        ->call('markAllRead')
        ->set('onlyUnread', true)
        ->assertSee('Keine ungelesenen Benachrichtigungen.');

    expect($this->leader->unreadNotifications()->count())->toBe(0);
});

test('an admin looking as the Kamerad leaves the notifications unread', function () {
    interest();
    $n = $this->leader->notifications()->sole();

    $this->actingAs($this->leader)->withSession([Impersonation::SESSION_KEY => User::factory()->create(['is_admin' => true])->id])
        ->get(route('notifications.open', $n->id))->assertRedirect();

    expect($n->fresh()->read_at)->toBeNull();
});

test('mail to a bare address (new e-mail) stays mail only; entries go after 180 days', function () {
    app(EmailChangeService::class)->request($this->member, 'hanna.neu@example.com');
    expect(UserNotification::count())->toBe(0);

    interest();
    $this->travel(UserNotification::KEEP_DAYS + 1)->days();
    $this->artisan('model:prune', ['--model' => [UserNotification::class]])->assertSuccessful();
    expect(UserNotification::count())->toBe(0);
});

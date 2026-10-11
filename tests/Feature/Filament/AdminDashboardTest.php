<?php

use App\Filament\Widgets\LatestFeedbackWidget;
use App\Filament\Widgets\LatestLoginsWidget;
use App\Filament\Widgets\ServiceStatusWidget;
use App\Models\ActivityLog;
use App\Models\FeedbackThread;
use App\Models\User;
use App\Services\ServiceHealth;
use App\Services\ServiceSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = User::factory()->admin()->create(['name' => 'Anna Admin']);
    $this->actingAs($this->admin);
});

test('the dashboard shows the last five logins, newest first', function () {
    foreach (['Hanna Hydrant', 'Bernd Blaulicht', 'Tim Tanklösch', 'Klara Kübel', 'Wilma Wehrführer', 'Paul Pumpe'] as $i => $name) {
        $user = User::factory()->create(['name' => $name]);
        ActivityLog::create(['created_at' => now()->addMinutes($i), 'user_id' => $user->id, 'event' => 'login', 'subject_type' => User::class, 'subject_id' => $user->id, 'subject_label' => $name]);
    }

    Livewire::test(LatestLoginsWidget::class)
        ->assertSeeInOrder(['Paul Pumpe', 'Wilma Wehrführer', 'Klara Kübel', 'Tim Tanklösch', 'Bernd Blaulicht'])
        ->assertDontSee('Hanna Hydrant');

    $this->get('/admin')->assertOk()->assertSee('Letzte Anmeldungen')->assertSee('Letztes Feedback');
});

test('the dashboard shows the last five feedbacks', function () {
    $user = User::factory()->create(['name' => 'Hanna Hydrant']);
    foreach (range(1, 6) as $i) {
        FeedbackThread::create(['user_id' => $user->id, 'subject' => 'Thema '.$i, 'status' => 'open', 'unread_for_admin' => true, 'last_message_at' => now()->addMinutes($i)]);
    }

    Livewire::test(LatestFeedbackWidget::class)
        ->assertSeeInOrder(['Thema 6', 'Thema 5', 'Thema 4', 'Thema 3', 'Thema 2'])
        ->assertDontSee('Thema 1');
});

test('the connections show mail, Google and OpenRouter; checked again on request', function () {
    config(['mail.default' => 'log']);
    app(ServiceSettings::class)->saveGoogleMapsKey('g-key');
    Http::fake([
        'maps.googleapis.com/*' => Http::response(['status' => 'OK', 'results' => []]),
        'openrouter.ai/*' => Http::response([], 401),
    ]);

    Livewire::test(ServiceStatusWidget::class)
        ->assertSee('Es werden keine E-Mails verschickt')
        ->assertSee('Der Schlüssel funktioniert für die Geocoding-API.')
        ->assertSee('Kein Schlüssel hinterlegt');

    // Cached: a new key shows only after "Jetzt prüfen"
    app(ServiceSettings::class)->saveOpenRouterKey('bad');
    Livewire::test(ServiceStatusWidget::class)->assertDontSee('OpenRouter lehnt den Schlüssel ab.')
        ->call('recheck')->assertSee('OpenRouter lehnt den Schlüssel ab.');

    Cache::forget(ServiceHealth::CACHE_KEY);
});

test('an unreachable SMTP server is an error', function () {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 2]);

    expect(app(ServiceHealth::class)->mail())->toMatchArray(['status' => 'error']);
});

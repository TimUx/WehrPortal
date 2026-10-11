<?php

use App\Models\Module;
use App\Models\User;
use App\Services\OrganizationSettings;
use App\Support\LinkPreview;
use Illuminate\Support\Facades\Cache;

const WHATSAPP = 'WhatsApp/2.23.20.0 A';

beforeEach(function () {
    Module::where('key', 'fahrzeugbuchung')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
});

test('a messenger fetching a module link sees the module name and description, not "Anmelden"', function () {
    $response = $this->withHeader('User-Agent', WHATSAPP)->get('/fahrzeugbuchung')->assertOk();

    $response->assertSee('<meta property="og:title" content="Fahrzeugbuchung – ', false)
        ->assertSee('og:description', false)
        ->assertSee('og:image', false)
        ->assertDontSee('Anmelden');
});

test('other links get the name of the Wehr', function () {
    $this->withHeader('User-Agent', 'TelegramBot (like TwitterBot)')->get('/')->assertOk()
        ->assertSee('<meta property="og:title" content="'.e(app(OrganizationSettings::class)->name()).'"', false);
});

test('people without login are still sent to the login page, which carries the preview of the wanted page', function () {
    $this->get('/fahrzeugbuchung')->assertRedirect('/anmelden');

    $this->get('/anmelden')->assertOk()->assertSee('<meta property="og:title" content="Fahrzeugbuchung – ', false);
});

test('logged-in users and public pages are not touched', function () {
    $user = User::factory()->create();
    $user->moduleAccess()->create(['module' => 'fahrzeugbuchung']);

    $this->actingAs($user)->withHeader('User-Agent', WHATSAPP)->get('/fahrzeugbuchung')->assertOk()->assertSee('Fahrzeugbuchung');
    expect(LinkPreview::isCrawler('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)'))->toBeFalse()
        ->and(LinkPreview::isCrawler('facebookexternalhit/1.1'))->toBeTrue();
});

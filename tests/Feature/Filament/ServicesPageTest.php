<?php

use App\Filament\Pages\OrganizationSettingsPage;
use App\Models\Setting;
use App\Models\User;
use App\Services\ServiceSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Cache::forget('openrouter_models');
    Http::fake([
        'openrouter.ai/api/v1/models' => Http::response(['data' => [
            ['id' => 'openai/gpt-4o', 'name' => 'GPT-4o'],
            ['id' => 'anthropic/claude-sonnet-4.5', 'name' => 'Claude Sonnet 4.5'],
        ]]),
    ]);
    $this->admin = User::factory()->admin()->create();
});

test('Dienste are part of Einstellungen and only accessible to admins', function () {
    $this->actingAs(User::factory()->create())->get('/admin/einstellungen')->assertForbidden();

    expect(OrganizationSettingsPage::getNavigationLabel())->toBe('Einstellungen');
    $this->actingAs($this->admin)->get(OrganizationSettingsPage::getUrl())->assertOk()
        ->assertSee('Einstellungen')->assertDontSee('Organization Settings Page')
        ->assertSee('Dienste')->assertSee('Google Maps')->assertSee('OpenRouter');
});

test('keys are saved encrypted and the model is stored', function () {
    Livewire::actingAs($this->admin)
        ->test(OrganizationSettingsPage::class)
        ->fillForm(['google_maps_key' => 'AIza-test-key', 'openrouter_key' => 'sk-or-test', 'openrouter_model' => 'openai/gpt-4o'], 'servicesForm')
        ->call('saveServices')
        ->assertHasNoFormErrors(form: 'servicesForm');

    $services = app(ServiceSettings::class);
    expect($services->googleMapsKey())->toBe('AIza-test-key')
        ->and($services->openRouterKey())->toBe('sk-or-test')
        ->and($services->aiModel())->toBe('openai/gpt-4o');

    // Never in plain text in the database
    expect(Setting::where('key', 'services_google_maps_key')->value('value'))->not->toContain('AIza-test-key');
    expect(Setting::where('key', 'services_openrouter_key')->value('value'))->not->toContain('sk-or-test');
});

test('a saved key is never sent to the browser and stays when the field is left empty', function () {
    app(ServiceSettings::class)->saveOpenRouterKey('sk-or-test');

    Livewire::actingAs($this->admin)
        ->test(OrganizationSettingsPage::class)
        ->assertDontSee('sk-or-test')
        ->assertSet('servicesData.openrouter_key', null)
        ->fillForm(['openrouter_model' => 'openai/gpt-4o'], 'servicesForm')
        ->call('saveServices');

    expect(app(ServiceSettings::class)->openRouterKey())->toBe('sk-or-test');
});

test('a saved key can be replaced or removed', function () {
    $services = app(ServiceSettings::class);
    $services->saveGoogleMapsKey('alt');
    $services->saveOpenRouterKey('alt');

    Livewire::actingAs($this->admin)
        ->test(OrganizationSettingsPage::class)
        ->fillForm(['google_maps_key' => 'neu', 'remove_openrouter_key' => true], 'servicesForm')
        ->call('saveServices');

    expect($services->googleMapsKey())->toBe('neu')->and($services->openRouterKey())->toBeNull();
});

test('the model list comes from OpenRouter and keeps a saved model that is no longer listed', function () {
    app(ServiceSettings::class)->saveAiModel('old/vanished-model');

    $models = app(ServiceSettings::class)->aiModels();

    expect($models)->toHaveKeys(['openai/gpt-4o', 'anthropic/claude-sonnet-4.5', 'old/vanished-model']);
});

test('without OpenRouter a short fallback list is offered', function () {
    Cache::forget('openrouter_models');
    Http::fake(['openrouter.ai/*' => Http::response('', 500)]);

    expect(app(ServiceSettings::class)->aiModels())->toHaveKey('openai/gpt-4o');
});

test('the connection tests report success and failure', function () {
    Http::fake([
        'openrouter.ai/api/v1/auth/key' => Http::sequence()->push(['data' => []], 200)->push([], 401),
        'maps.googleapis.com/*' => Http::sequence()->push(['status' => 'OK'])->push(['status' => 'REQUEST_DENIED', 'error_message' => 'API key invalid']),
    ]);
    $services = app(ServiceSettings::class);

    expect($services->testOpenRouter('gut')['ok'])->toBeTrue()
        ->and($services->testOpenRouter('schlecht')['ok'])->toBeFalse()
        ->and($services->testGoogleMaps('gut')['ok'])->toBeTrue()
        ->and($services->testGoogleMaps('schlecht')['message'])->toContain('API key invalid');
});

test('the test button without any key says so', function () {
    Livewire::actingAs($this->admin)
        ->test(OrganizationSettingsPage::class)
        ->call('testOpenRouter')
        ->assertNotified('Kein Schlüssel vorhanden');
});

test('a key saved under another APP_KEY counts as not set', function () {
    Setting::set('services_openrouter_key', 'kein-gueltiger-chiffretext');

    expect(app(ServiceSettings::class)->openRouterKey())->toBeNull();
});

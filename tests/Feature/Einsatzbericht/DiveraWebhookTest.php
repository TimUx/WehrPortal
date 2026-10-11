<?php

use App\Models\ActivityLog;
use App\Models\Module;
use App\Models\User;
use App\Services\ServiceSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Einsatzbericht\src\Livewire\Settings;
use Modules\Einsatzbericht\src\Models\DiveraRequest;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Einsatzbericht\src\Services\DiveraWebhook;

beforeEach(function () {
    Module::where('key', 'einsatzbericht')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $this->travelTo(Carbon::parse('2027-05-10 19:12:30', 'Europe/Berlin'));
    Http::preventStrayRequests();
    DiveraWebhook::setEnabled(true);
    $this->url = DiveraWebhook::url();
    $this->alarm = ['id' => 4711, 'title' => 'FEU 2 – Scheunenbrand', 'text' => 'Rauchentwicklung aus Scheune, Personen nicht in Gefahr', 'address' => 'Dorfstraße 1, 12345 Musterstadt', 'lat' => '51.1657', 'lng' => '10.4515'];
});

test('an alarm becomes a draft with keyword, message, address, coordinates and alarm time', function () {
    $this->postJson($this->url, $this->alarm)->assertOk()->assertJson(['ok' => true]);

    $report = Report::sole();
    expect($report)
        ->status->toBe('active')->source->toBe('divera')->divera_id->toBe('4711')
        ->keyword->toBe('FEU 2 – Scheunenbrand')->alarm_message->toBe('Rauchentwicklung aus Scheune, Personen nicht in Gefahr')
        ->address->toBe('Dorfstraße 1, 12345 Musterstadt')->latitude->toBe(51.1657)->longitude->toBe(10.4515)
        ->number->toBe('2027/001');
    expect($report->alarmed_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i:s'))->toBe('10.05.2027 19:12:30');

    $entry = DiveraRequest::sole();
    expect($entry)->status->toBe('created')->report_id->toBe($report->id)
        ->and(json_decode($entry->body, true))->toBe($this->alarm);

    // Logged as made by the system, not as a change of anyone
    expect(ActivityLog::where('subject_type', Report::class)->where('event', 'created')->sole()->user_id)->toBeNull()
        ->and(ActivityLog::where('subject_type', DiveraRequest::class)->exists())->toBeFalse();
});

test('a re-alarm of the same Einsatz attaches to the existing draft', function () {
    $this->postJson($this->url, $this->alarm)->assertOk();
    $this->travel(5)->minutes();
    $this->postJson($this->url, [...$this->alarm, 'text' => 'Nachalarmierung: Drehleiter'])->assertOk();

    expect(Report::count())->toBe(1)
        ->and(DiveraRequest::latest('id')->first())->status->toBe('attached')->report_id->toBe(Report::sole()->id);

    // Without an id: the same keyword and address shortly after
    $plain = collect($this->alarm)->except('id')->all();
    $this->postJson($this->url, [...$plain, 'title' => 'TH 1'])->assertOk();
    $this->postJson($this->url, [...$plain, 'title' => 'TH 1'])->assertOk();
    expect(Report::count())->toBe(2);
});

test('Probealarme and chosen keywords make no draft', function () {
    $this->postJson($this->url, [...$this->alarm, 'id' => 1, 'title' => 'Probealarm Sirene'])->assertOk();
    expect(Report::count())->toBe(0)->and(DiveraRequest::sole()->status)->toBe('filtered');

    DiveraWebhook::setMapping([...DiveraWebhook::mapping(), 'test' => 'is_test']);
    $this->postJson($this->url, [...$this->alarm, 'id' => 2, 'is_test' => true])->assertOk();
    expect(Report::count())->toBe(0);
});

test('the field mapping is configurable, also nested and as form data, XML, text or GET', function () {
    DiveraWebhook::setMapping([...DiveraWebhook::mapping(), 'keyword' => 'alarm.stichwort', 'address' => 'alarm.ort']);
    $this->postJson($this->url, ['alarm' => ['stichwort' => 'TH 2', 'ort' => 'B75, Musterstadt']])->assertOk();
    expect(Report::latest('id')->first())->keyword->toBe('TH 2')->address->toBe('B75, Musterstadt');

    DiveraWebhook::setMapping(DiveraWebhook::DEFAULT_MAPPING);
    $this->post($this->url, ['title' => 'FEU 1', 'address' => 'Am Teich 3'])->assertOk();                           // form data
    expect(Report::latest('id')->first()->keyword)->toBe('FEU 1');

    $this->call('POST', $this->url, [], [], [], ['CONTENT_TYPE' => 'application/xml'], '<alarm><id>99</id><title>FEU 3</title><address>Hof 1</address></alarm>')->assertOk();
    expect(Report::latest('id')->first())->keyword->toBe('FEU 3')->divera_id->toBe('99');

    $this->call('POST', $this->url, [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'Brand Wohnhaus, Hauptstraße 5')->assertOk();
    expect(Report::latest('id')->first())->alarm_message->toBe('Brand Wohnhaus, Hauptstraße 5');

    $this->get($this->url.'?title=FEU%204&address=Weg%201&id=100')->assertOk();
    expect(Report::latest('id')->first())->keyword->toBe('FEU 4');
});

test('without coordinates the address is geocoded with the Google key', function () {
    app(ServiceSettings::class)->saveGoogleMapsKey('AIza-test');
    Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'OK', 'results' => [['geometry' => ['location' => ['lat' => 51.17, 'lng' => 10.45]]]]])]);

    $this->postJson($this->url, collect($this->alarm)->except(['lat', 'lng'])->all())->assertOk();

    expect(Report::sole())->latitude->toBe(51.17)->longitude->toBe(10.45);
});

test('a wrong key is a 404; a switched-off webhook only stores the request', function () {
    $this->postJson(str_replace(DiveraWebhook::key(), str_repeat('x', 48), $this->url), $this->alarm)->assertNotFound();

    DiveraWebhook::setEnabled(false);
    $this->postJson($this->url, $this->alarm)->assertOk();
    expect(Report::count())->toBe(0)->and(DiveraRequest::sole()->status)->toBe('disabled');

    // A new key replaces the old address
    DiveraWebhook::setEnabled(true);
    $old = $this->url;
    DiveraWebhook::regenerateKey();
    $this->postJson($old, $this->alarm)->assertNotFound();
});

test('requests per address are limited', function () {
    foreach (range(1, 30) as $i) {
        $this->postJson($this->url, [...$this->alarm, 'id' => $i])->assertOk();
    }

    $this->postJson($this->url, [...$this->alarm, 'id' => 31])->assertStatus(429);
});

test('broken data is stored as error and does not stop the endpoint', function () {
    DiveraWebhook::setMapping([...DiveraWebhook::mapping(), 'keyword' => 'title']);
    $this->postJson($this->url, ['title' => str_repeat('A', 5000), 'id' => 'x'])->assertOk();

    expect(DiveraRequest::sole()->status)->toBeIn(['created', 'error']);
});

test('the Wehrführung switches the webhook, copies the address, sets mapping and filters and sees the requests', function () {
    $chief = User::factory()->create(['name' => 'Wilma Wehrführer']);
    $chief->moduleAccess()->create(['module' => 'einsatzbericht', 'role' => 'wehrführung']);
    $this->postJson($this->url, $this->alarm);

    Livewire::actingAs($chief)->test(Settings::class)
        ->assertSee($this->url)
        ->assertSee('Einsatz angelegt')
        ->assertSee('Felder im letzten Request')->assertSee('address')
        ->set('mapping.keyword', 'stichwort')
        ->set('filters', "Probealarm\nÜbung")
        ->set('diveraEnabled', false)
        ->call('saveDivera')
        ->assertSee('ausgeschaltet');

    expect(DiveraWebhook::enabled())->toBeFalse()->and(DiveraWebhook::mapping()['keyword'])->toBe('stichwort')->and(DiveraWebhook::filters())->toBe(['Probealarm', 'Übung']);

    $leader = User::factory()->create();
    $leader->moduleAccess()->create(['module' => 'einsatzbericht', 'role' => 'einsatzleiter']);
    $this->actingAs($leader)->get('/einsatzbericht/einstellungen')->assertForbidden();
});

test('the draft shows its DIVERA origin and message', function () {
    $this->postJson($this->url, $this->alarm);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/einsatzbericht/'.Report::sole()->id)->assertOk()
        ->assertSee('Aus DIVERA 24/7 angelegt')->assertSee('Rauchentwicklung aus Scheune');
});

test('raw requests are pruned after 90 days', function () {
    DiveraRequest::create(['received_at' => now()->subDays(91), 'method' => 'POST', 'status' => 'created']);
    DiveraRequest::create(['received_at' => now()->subDays(5), 'method' => 'POST', 'status' => 'created']);

    $this->artisan('model:prune', ['--model' => [DiveraRequest::class]])->assertSuccessful();

    expect(DiveraRequest::count())->toBe(1);
});

// ─── test alarm from the app ─────────────────────────────────────────────────

test('a test alarm from the app runs the same way as a DIVERA request and makes a draft', function () {
    $chief = User::factory()->create(['name' => 'Wilma Wehrführer']);
    $chief->moduleAccess()->create(['module' => 'einsatzbericht', 'role' => 'wehrführung']);

    Livewire::actingAs($chief)->test(Settings::class)
        ->set('test.keyword', 'TH 1 – Test')
        ->set('test.address', 'Am Teich 3, Musterstadt')
        ->set('test.latitude', '51.17')
        ->set('test.longitude', '10.45')
        ->call('sendTestAlarm')
        ->assertHasNoErrors()
        ->assertSee('Test-Alarm: Einsatz angelegt');

    $report = Report::sole();
    expect($report)->source->toBe('divera')->keyword->toBe('TH 1 – Test')->address->toBe('Am Teich 3, Musterstadt')->latitude->toBe(51.17)
        ->and($report->alarm_message)->toBe('Test-Alarm aus openwehr, bitte ignorieren')
        ->and(json_decode(DiveraRequest::sole()->body, true))->toMatchArray(['title' => 'TH 1 – Test', 'address' => 'Am Teich 3, Musterstadt']);

    // Each test alarm is its own alarm, no re-alarm
    Livewire::actingAs($chief)->test(Settings::class)->call('sendTestAlarm');
    expect(Report::count())->toBe(2);
});

test('a test alarm uses the names of the field mapping and can be a Probealarm', function () {
    DiveraWebhook::setMapping([...DiveraWebhook::mapping(), 'keyword' => 'alarm.stichwort', 'test' => 'probe']);

    $entry = app(DiveraWebhook::class)->testAlarm(['keyword' => 'FEU 2', 'message' => null, 'address' => null, 'latitude' => null, 'longitude' => null, 'test' => false]);
    expect(json_decode($entry->body, true)['alarm']['stichwort'])->toBe('FEU 2')->and(Report::sole()->keyword)->toBe('FEU 2');

    $probe = app(DiveraWebhook::class)->testAlarm(['keyword' => 'FEU 2', 'message' => null, 'address' => null, 'latitude' => null, 'longitude' => null, 'test' => true]);
    expect($probe->status)->toBe('filtered')->and(Report::count())->toBe(1);
});

test('a test alarm needs the webhook switched on; a failing public address is reported', function () {
    $chief = User::factory()->admin()->create();

    Http::fake(['*' => Http::response('', 502)]);
    Livewire::actingAs($chief)->test(Settings::class)->set('test.via_url', true)->call('sendTestAlarm')
        ->assertSee('Test-Alarm fehlgeschlagen: Die Adresse antwortet mit HTTP 502.');

    DiveraWebhook::setEnabled(false);
    Livewire::actingAs($chief)->test(Settings::class)->call('sendTestAlarm')->assertSee('Der Webhook ist ausgeschaltet');
    expect(Report::count())->toBe(0);
});

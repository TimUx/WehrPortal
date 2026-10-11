<?php

use App\Livewire\Dashboard\Home;
use App\Models\Module;
use App\Models\User;
use App\Services\ServiceSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Einsatzbericht\src\Livewire\ReportEditor;
use Modules\Einsatzbericht\src\Services\ReportService;

beforeEach(function () {
    Module::where('key', 'einsatzbericht')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $this->travelTo(Carbon::parse('2027-05-10 20:00', 'Europe/Berlin'));

    $this->leader = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $this->leader->moduleAccess()->create(['module' => 'einsatzbericht', 'role' => 'einsatzleiter']);
    $this->member = User::factory()->create(['name' => 'Hanna Hydrant']);
    $this->member->moduleAccess()->create(['module' => 'einsatzbericht']);
    $this->r = app(ReportService::class);
});

test('a new report is a draft until "Einsatz starten"; then it runs for everyone', function () {
    $report = $this->r->create($this->leader);
    expect($report->status)->toBe('draft')->and($report->isRunning())->toBeFalse();

    $editor = Livewire::actingAs($this->leader)->test(ReportEditor::class, ['report' => $report])
        ->assertSee('Einsatz starten')->assertSee('Entwurf speichern')
        ->set('form.keyword', 'FEU 2')->call('save')
        ->assertDispatched('report-saved', fn ($event, $params) => str_starts_with($params['message'], 'Entwurf gespeichert. Noch offen:'));
    expect($report->refresh()->status)->toBe('draft');

    // A draft is not shown to the Kameraden, has no Lagemeldungen and no QR access
    $this->actingAs($this->member)->get('/einsatzbericht')->assertOk()->assertDontSee('FEU 2');
    $this->actingAs($this->member)->get(route('einsatzbericht.situation', $report))->assertForbidden();

    $this->actingAs($this->leader);
    $editor->call('start')
        ->assertDispatched('report-saved', fn ($event, $params) => str_starts_with($params['message'], 'Einsatz gestartet.') && $params['field'] === null)
        ->assertSee('Aktuell')->assertDontSee('Entwurf speichern');
    expect($report->refresh())->status->toBe('active')->isRunning()->toBeTrue();
    $this->actingAs($this->member)->get('/einsatzbericht')->assertOk()->assertSee('Laufende Einsätze')->assertSee('FEU 2');

    // Starting twice is refused; an active one is closed like before
    expect(fn () => $this->r->start($this->leader, $report->refresh()))->toThrow(ValidationException::class, 'Nur ein Entwurf');
});

test('the start page counts drafts and current Einsätze for the Einsatzleiter', function () {
    $this->r->create($this->leader);
    $this->r->start($this->leader, $this->r->create($this->leader));

    Livewire::actingAs($this->leader)->test(Home::class)
        ->assertSee('1 Einsatz ist aktuell')->assertSee('1 Bericht ist noch Entwurf');
});

test('typing the address shows Google suggestions; choosing one sets address, Objekt and point', function () {
    app(ServiceSettings::class)->saveGoogleMapsKey('AIza-test');
    Http::fake([
        'places.googleapis.com/v1/places:autocomplete' => Http::response(['suggestions' => [
            ['placePrediction' => ['placeId' => 'ChIJ-schule-123', 'text' => ['text' => 'Grundschule Musterstadt, Schulweg 3, Musterstadt, Deutschland'],
                'structuredFormat' => ['mainText' => ['text' => 'Grundschule Musterstadt'], 'secondaryText' => ['text' => 'Schulweg 3, Musterstadt, Deutschland']]]],
            ['placePrediction' => ['placeId' => 'ChIJ-haupt-5', 'text' => ['text' => 'Hauptstraße 5, Musterstadt, Deutschland'],
                'structuredFormat' => ['mainText' => ['text' => 'Hauptstraße 5'], 'secondaryText' => ['text' => 'Musterstadt, Deutschland']]]],
        ]]),
        'places.googleapis.com/v1/places/ChIJ-schule-123*' => Http::response([
            'formattedAddress' => 'Schulweg 3, 12345 Musterstadt, Deutschland', 'location' => ['latitude' => 51.2, 'longitude' => 10.3],
            'displayName' => ['text' => 'Grundschule Musterstadt'], 'types' => ['school', 'point_of_interest'],
        ]),
    ]);
    $report = $this->r->create($this->leader);

    Livewire::actingAs($this->leader)->test(ReportEditor::class, ['report' => $report])
        ->set('form.address', 'Gr')->assertSet('addressSuggestions', [])
        ->set('form.address', 'Grundschule Muster')
        ->assertSee('Schulweg 3, Musterstadt')->assertSee('Vorschläge von Google')
        ->call('chooseAddress', 'ChIJ-schule-123')
        ->assertSet('form.address', 'Schulweg 3, 12345 Musterstadt')->assertSet('form.object', 'Grundschule Musterstadt')
        ->assertSet('addressSuggestions', [])
        ->assertDispatched('einsatz-location', lat: 51.2, lng: 10.3)
        ->call('save');

    expect($report->refresh())->address->toBe('Schulweg 3, 12345 Musterstadt')->latitude->toEqual(51.2)->longitude->toEqual(10.3);

    // The key is sent as header to Google, never to the browser; no extra geocoding for a chosen address
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'places:autocomplete') && $r->header('X-Goog-Api-Key')[0] === 'AIza-test' && $r['includedRegionCodes'] === ['de']);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'geocode'));
});

test('without a Google key the address is a plain field; Google errors give no suggestions', function () {
    $report = $this->r->create($this->leader);
    Http::fake();

    Livewire::actingAs($this->leader)->test(ReportEditor::class, ['report' => $report])
        ->set('form.address', 'Hauptstraße 5, Musterstadt')->assertSet('addressSuggestions', [])->assertDontSee('Vorschläge von Google')
        ->call('save');
    Http::assertNothingSent();
    expect($report->refresh()->address)->toBe('Hauptstraße 5, Musterstadt');

    app(ServiceSettings::class)->saveGoogleMapsKey('AIza-test');
    Http::fake(['places.googleapis.com/*' => Http::response(['error' => ['message' => 'Places API (New) has not been used in project']], 403)]);
    Livewire::actingAs($this->leader)->test(ReportEditor::class, ['report' => $report])
        ->set('form.address', 'Hauptstraße 7, Musterstadt')->assertSet('addressSuggestions', []);
});

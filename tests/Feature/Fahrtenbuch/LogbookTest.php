<?php

use App\Livewire\Dashboard\Home;
use App\Models\Module;
use App\Models\User;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\LocationAccess;
use Extensions\Locations\Services\StandardRoleAssignments;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Einsatzbericht\src\Services\ReportService;
use Modules\Fahrtenbuch\src\Livewire\FuelForm;
use Modules\Fahrtenbuch\src\Livewire\Overview;
use Modules\Fahrtenbuch\src\Livewire\TripForm;
use Modules\Fahrtenbuch\src\Models\Fueling;
use Modules\Fahrtenbuch\src\Models\Trip;
use Modules\Fahrtenbuch\src\Notifications\OpenTripNotification;
use Modules\Fahrtenbuch\src\Services\LogbookService;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeugbuchung\src\Services\BookingService;
use Modules\Fahrzeuge\src\Models\Profile;

beforeEach(function () {
    config(['locations.enabled' => true]);
    Module::whereIn('key', ['fahrtenbuch', 'fahrzeugbuchung', 'fahrzeuge', 'einsatzbericht'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Notification::fake();

    $this->wart = User::factory()->create(['name' => 'Fritz Fahrtenbuchwart']);
    $this->wart->moduleAccess()->create(['module' => 'fahrtenbuch', 'role' => 'fahrtenbuchwart']);
    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant']);
    foreach (['fahrtenbuch', 'fahrzeugbuchung', 'fahrzeuge'] as $module) {
        $this->hanna->moduleAccess()->create(['module' => $module]);
    }
    $this->bernd = User::factory()->create(['name' => 'Bernd Blaulicht']);

    $this->hlf = Vehicle::factory()->create(['name' => 'HLF 20', 'is_active' => true]);
    $this->mtw = Vehicle::factory()->create(['name' => 'MTW', 'is_active' => true]);
    Profile::create(['vehicle_id' => $this->mtw->id, 'mileage_km' => 48200]);
    $this->l = app(LogbookService::class);
});

function tripData(array $overrides = []): array
{
    return ['vehicle_id' => test()->mtw->id, 'driver_id' => test()->hanna->id, 'start_km' => 48200, 'origin' => 'Gerätehaus', 'purpose' => 'dienstfahrt',
        'purpose_note' => 'Schläuche zur Prüfung', ...$overrides];
}

test('a trip starts with the last Kilometerstand and ends with the new one, which the Fahrzeugverwaltung takes over', function () {
    Livewire::actingAs($this->hanna)->withQueryParams(['fahrzeug' => $this->mtw->id])->test(TripForm::class)
        ->assertSet('startKm', '48200')->assertSet('origin', 'Gerätehaus')
        ->set('purpose', 'dienstfahrt')->call('save')->assertHasErrors('purpose_note')
        ->set('purposeNote', 'Schläuche zur Prüfung')->call('save')->assertHasNoErrors()->assertRedirect();

    $trip = Trip::sole();
    expect($trip)->isOpen()->toBeTrue()->driver_id->toBe($this->hanna->id)->created_by->toBe($this->hanna->id);
    expect(fn () => $this->l->start($this->wart, tripData(['driver_id' => $this->bernd->id])))->toThrow(ValidationException::class, 'schon unterwegs');

    Livewire::actingAs($this->hanna)->withQueryParams(['fahrt' => $trip->id])->test(TripForm::class)
        ->assertSet('mode', 'end')->assertSet('destination', 'Gerätehaus')
        ->set('endKm', '48100')->call('save')->assertHasErrors('end_km')
        ->set('endKm', '58000')->call('save')->assertHasErrors('end_km')
        ->set('endKm', '48262')->set('destination', 'Atemschutzwerkstatt Kreisstadt')->call('save')->assertHasNoErrors();

    expect($trip->fresh())->distance()->toBe(62)->isOpen()->toBeFalse()
        ->and(Profile::where('vehicle_id', $this->mtw->id)->value('mileage_km'))->toBe(48262)
        ->and($this->l->lastKm($this->mtw))->toBe(48262);

    // The Kilometerstand never goes backwards
    expect(fn () => $this->l->start($this->hanna, tripData(['start_km' => 48250])))->toThrow(ValidationException::class, 'letzten Fahrt');
});

test('the vehicle of a running booking and the running Einsatz are filled in by themselves', function () {
    app(BookingService::class)->create(['vehicle_id' => $this->mtw->id, 'starts_at' => now()->subMinutes(10), 'ends_at' => now()->addHours(3), 'purpose' => 'Jugendfeuerwehr Ausflug'], $this->hanna->id);

    Livewire::actingAs($this->hanna)->test(TripForm::class)
        ->assertSet('vehicleId', (string) $this->mtw->id)->assertSet('purposeNote', 'Jugendfeuerwehr Ausflug')->assertSee('Fahrzeug aus deiner Buchung übernommen')
        ->call('save')->assertHasNoErrors();
    expect(Trip::sole()->booking?->purpose)->toBe('Jugendfeuerwehr Ausflug');

    $leader = User::factory()->create();
    $leader->moduleAccess()->create(['module' => 'einsatzbericht', 'role' => 'einsatzleiter']);
    $reports = app(ReportService::class);
    $report = $reports->create($leader);
    $reports->update($leader, $report, ['keyword' => 'TH 1']);
    $reports->start($leader, $report);
    $reports->addVehicle($leader, $report, $this->hlf->id);

    $this->bernd->moduleAccess()->create(['module' => 'fahrtenbuch']);
    Livewire::actingAs($this->bernd)
        ->withQueryParams(['fahrzeug' => $this->hlf->id])->test(TripForm::class)
        ->assertSet('purpose', 'einsatz')->assertSet('reportId', (string) $report->id)
        ->set('startKm', '12000')->call('save')->assertHasNoErrors();
    expect(Trip::where('vehicle_id', $this->hlf->id)->sole()->report_id)->toBe($report->id);

    $this->actingAs($leader)->get(route('einsatzbericht.show', $report))->assertOk()->assertSee('Fahrtenbuch')->assertSee('HLF 20');
});

test('trips entered afterwards: no overlap, gaps in the Kilometerstand are shown', function () {
    $day = Carbon::parse('-3 days 09:00', 'Europe/Berlin');
    $first = $this->l->record($this->hanna, tripData(['started_at' => $day->format('Y-m-d\TH:i'), 'ended_at' => $day->copy()->addHours(2)->format('Y-m-d\TH:i'), 'end_km' => 48250, 'destination' => 'Kreisstadt']));
    expect(fn () => $this->l->record($this->hanna, tripData(['started_at' => $day->copy()->addHour()->format('Y-m-d\TH:i'), 'ended_at' => $day->copy()->addHours(3)->format('Y-m-d\TH:i'), 'start_km' => 48250, 'end_km' => 48260, 'destination' => 'x'])))
        ->toThrow(ValidationException::class, 'schon eine Fahrt');
    $second = $this->l->record($this->hanna, tripData(['started_at' => $day->copy()->addDay()->format('Y-m-d\TH:i'), 'ended_at' => $day->copy()->addDay()->addHour()->format('Y-m-d\TH:i'), 'start_km' => 48290, 'end_km' => 48300, 'destination' => 'Gerätehaus']));

    expect(LogbookService::gaps(Trip::all()))->toBe([$second->id => 40]);
    $this->actingAs($this->hanna)->get(route('fahrtenbuch.vehicle', $this->mtw))->assertOk()->assertSee('Lücke 40 km davor');
    expect($first->distance())->toBe(50);
});

test('the Fahrtenbuchwart corrects with a reason (the old values stay) or cancels; nobody deletes', function () {
    $trip = $this->l->record($this->hanna, tripData(['started_at' => now('Europe/Berlin')->subHours(3)->format('Y-m-d\TH:i'), 'ended_at' => now('Europe/Berlin')->subHour()->format('Y-m-d\TH:i'), 'end_km' => 48300, 'destination' => 'Kreisstadt']));

    expect(fn () => $this->l->correct($this->hanna, $trip, tripData(['end_km' => 48280, 'destination' => 'Kreisstadt']), 'Tippfehler'))->toThrow(ValidationException::class, 'Fahrtenbuchwart');

    Livewire::actingAs($this->wart)->withQueryParams(['fahrt' => $trip->id, 'korrigieren' => 1])->test(TripForm::class)
        ->assertSet('mode', 'correct')->set('endKm', '48280')->call('save')->assertHasErrors('reason')
        ->set('reason', 'Tippfehler beim Kilometerstand')->call('save')->assertHasNoErrors();

    $revision = $trip->revisions()->sole();
    expect($trip->fresh()->end_km)->toBe(48280)->and($revision->before['end_km'])->toBe(48300)->and($revision->after['end_km'])->toBe(48280)
        ->and($revision->reason)->toBe('Tippfehler beim Kilometerstand');

    $this->l->void($this->wart, $trip->fresh(), 'doppelt eingetragen');
    expect(Trip::count())->toBe(1)->and($trip->fresh()->voided_at)->not->toBeNull()
        ->and($this->l->csv($this->mtw, now('Europe/Berlin')->year))->toContain('ja: doppelt eingetragen');
});

test('fuel stops: consumption from full tank to full tank, receipt and statistics', function () {
    $fuel = fn (string $when, int $km, string $liters, bool $full) => $this->l->addFueling($this->hanna, ['vehicle_id' => $this->mtw->id, 'fueled_at' => Carbon::parse($when, 'Europe/Berlin')->format('Y-m-d\TH:i'),
        'km' => $km, 'liters' => $liters, 'full_tank' => $full, 'cost_cents' => 15000]);
    $fuel('-30 days', 48300, '60,00', true);
    $fuel('-20 days', 48800, '50,5', false);
    $fuel('-10 days', 49300, '39,5', true);

    $intervals = $this->l->consumption($this->mtw);
    expect($intervals)->toHaveCount(1)->and($intervals->first())->toMatchArray(['km' => 1000, 'liters' => 90.0, 'per100' => 9.0])
        ->and(Profile::where('vehicle_id', $this->mtw->id)->value('mileage_km'))->toBe(49300);
    expect(fn () => $fuel('-5 days', 40000, '10', true))->toThrow(ValidationException::class, 'unter dem letzten');

    Livewire::actingAs($this->hanna)->withQueryParams(['fahrzeug' => $this->mtw->id])->test(FuelForm::class)
        ->assertSet('km', '49300')->set('liters', '12,3')->set('cost', '20,10')->set('fullTank', false)
        ->set('receipt', UploadedFile::fake()->image('bon.jpg', 800, 1200))->call('save')->assertHasNoErrors();
    expect(Fueling::latest('id')->first())->liters->toBe(12.3)->cost_cents->toBe(2010)->receipt_mime->toBe('image/webp');

    $stats = $this->l->statistics((int) now('Europe/Berlin')->year)->firstWhere('vehicle.id', $this->mtw->id);
    expect($stats['per100'])->toBe(9.0)->and($stats['cost'])->toBe(47010);
});

test('export and statistics are for the Fahrtenbuchwart', function () {
    $this->l->record($this->hanna, tripData(['started_at' => now('Europe/Berlin')->subHours(3)->format('Y-m-d\TH:i'), 'ended_at' => now('Europe/Berlin')->subHour()->format('Y-m-d\TH:i'), 'end_km' => 48300, 'destination' => 'Kreisstadt']));
    $year = now('Europe/Berlin')->year;

    $this->actingAs($this->hanna)->get(route('fahrtenbuch.export.trips', [$this->mtw, $year]))->assertForbidden();
    $this->actingAs($this->hanna)->get(route('fahrtenbuch.statistics'))->assertForbidden();
    $csv = $this->actingAs($this->wart)->get(route('fahrtenbuch.export.trips', [$this->mtw, $year]))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
    expect($csv)->toContain('Datum;Beginn;Ende')->toContain('48200;48300;100')->toContain('Hanna Hydrant');
    $this->actingAs($this->wart)->get(route('fahrtenbuch.export.print', [$this->mtw, $year]))->assertOk()->assertSee('Fahrtenbuch MTW');
    $this->actingAs($this->wart)->get(route('fahrtenbuch.statistics'))->assertOk()->assertSee('MTW')->assertSee('100');
});

test('open trips: reminder after hours, start page card, overview and the Fahrzeugverwaltung', function () {
    $trip = $this->l->start($this->hanna, tripData());
    Livewire::actingAs($this->hanna)->test(Home::class)->assertSee('Du bist mit MTW unterwegs');

    $this->travel(13)->hours();
    $this->artisan('fahrtenbuch:remind')->assertSuccessful();
    Notification::assertSentTo($this->hanna, OpenTripNotification::class);
    Notification::fake();
    $this->artisan('fahrtenbuch:remind')->assertSuccessful();
    Notification::assertNothingSent();

    $this->actingAs($this->hanna)->get(route('fahrtenbuch.index'))->assertOk()->assertSee('Du bist mit MTW unterwegs')->assertSee('Unterwegs: Hanna Hydrant')->assertDontSee('Kiosk');
    $this->actingAs($this->hanna)->get(route('fahrzeuge.show', $this->mtw))->assertOk()->assertSee('Fahrtenbuch '.now('Europe/Berlin')->year)->assertSee('1 Fahrten');
    expect($trip->fresh()->reminded_at)->not->toBeNull();
});

test('Fahrtenbuch access and driver choices follow the vehicle Standort', function () {
    $north = Location::create(['name' => 'Wache Nord', 'slug' => 'fahrtenbuch-nord']);
    $south = Location::create(['name' => 'Wache Süd', 'slug' => 'fahrtenbuch-sued']);
    $this->hanna->update(['ext_location_id' => $north->id]);
    $this->bernd->update(['ext_location_id' => $south->id]);
    $this->bernd->moduleAccess()->create(['module' => 'fahrtenbuch']);
    app(LocationAccess::class)->forget();

    $northVehicle = Vehicle::factory()->create(['name' => 'HLF Nord', 'is_active' => true, 'ext_location_id' => $north->id]);
    $southVehicle = Vehicle::factory()->create(['name' => 'HLF Süd', 'is_active' => true, 'ext_location_id' => $south->id]);
    $trip = $this->l->start($this->bernd, tripData(['vehicle_id' => $southVehicle->id, 'driver_id' => $this->bernd->id, 'start_km' => 1000]));
    $fueling = Fueling::create([
        'vehicle_id' => $southVehicle->id, 'user_id' => $this->bernd->id, 'fueled_at' => now(), 'km' => 1000,
        'liters' => 10, 'cost_cents' => 1000, 'fuel' => 'diesel', 'full_tank' => true,
        'receipt' => base64_encode('%PDF-1.4 test'), 'receipt_mime' => 'application/pdf',
    ]);

    expect(LogbookService::canAccessVehicle($this->hanna, $northVehicle))->toBeTrue()
        ->and(LogbookService::canAccessVehicle($this->hanna, $southVehicle))->toBeFalse();

    Livewire::actingAs($this->hanna)->test(Overview::class)
        ->assertSee('HLF Nord')->assertSee('Wache Nord')->assertDontSee('HLF Süd')->assertDontSee('Wache Süd');
    Livewire::actingAs($this->hanna)->withQueryParams(['fahrzeug' => $northVehicle->id])->test(TripForm::class)
        ->assertSee('Hanna Hydrant')->assertDontSee('Bernd Blaulicht');

    $this->actingAs($this->hanna)->get(route('fahrtenbuch.trip', ['fahrt' => $trip->id]))->assertNotFound();
    $this->actingAs($this->hanna)->get(route('fahrtenbuch.receipt', $fueling))->assertNotFound();
    $this->actingAs($this->hanna)->get(route('fahrtenbuch.vehicle', $southVehicle))->assertNotFound();

    expect(fn () => $this->l->end($this->hanna, $trip, [
        'ended_at' => now()->format('Y-m-d\TH:i'), 'end_km' => 1005, 'destination' => 'Gerätehaus',
    ]))->toThrow(ValidationException::class, 'anderen Standort');

    auth()->login(User::factory()->admin()->create());
    expect(fn () => $this->l->start($this->hanna, tripData([
        'vehicle_id' => $northVehicle->id, 'driver_id' => $this->bernd->id, 'start_km' => 2000,
    ])))->toThrow(ValidationException::class, 'Standort des Fahrzeugs');
});

test('local Wehrführung manages only trips and exports for its assigned Standort', function () {
    $north = Location::create(['name' => 'Wache Nord', 'slug' => 'fahrtenbuch-leitung-nord']);
    $south = Location::create(['name' => 'Wache Süd', 'slug' => 'fahrtenbuch-leitung-sued']);
    $leader = User::factory()->create(['name' => 'Wilma Wehrführung', 'ext_location_id' => $north->id]);
    $south->members()->attach($leader->id, ['role' => 'member']);
    $this->hanna->update(['ext_location_id' => $north->id]);
    $this->bernd->update(['ext_location_id' => $south->id]);
    $this->bernd->moduleAccess()->create(['module' => 'fahrtenbuch']);
    app(StandardRoleAssignments::class)->grant(
        User::factory()->admin()->create(),
        $leader->id,
        'wehrfuehrung',
        $north->id,
        null,
        'Standortbezogene Fahrtenbuchverwaltung',
    );

    $northVehicle = Vehicle::factory()->create(['name' => 'HLF Nord', 'is_active' => true, 'ext_location_id' => $north->id]);
    $southVehicle = Vehicle::factory()->create(['name' => 'HLF Süd', 'is_active' => true, 'ext_location_id' => $south->id]);
    $startedAt = now('Europe/Berlin')->subHours(4)->format('Y-m-d\TH:i');
    $endedAt = now('Europe/Berlin')->subHours(3)->format('Y-m-d\TH:i');
    $northTrip = $this->l->record($this->hanna, tripData([
        'vehicle_id' => $northVehicle->id, 'started_at' => $startedAt, 'ended_at' => $endedAt, 'start_km' => 100, 'end_km' => 120, 'destination' => 'Wache Nord',
    ]));
    $southTrip = $this->l->record($this->bernd, tripData([
        'vehicle_id' => $southVehicle->id, 'driver_id' => $this->bernd->id, 'started_at' => $startedAt, 'ended_at' => $endedAt, 'start_km' => 200, 'end_km' => 240, 'destination' => 'Wache Süd',
    ]));
    $year = now('Europe/Berlin')->year;

    expect(LogbookService::canManage($leader))->toBeTrue()
        ->and(LogbookService::canManageVehicle($leader, $northVehicle))->toBeTrue()
        ->and(LogbookService::canManageVehicle($leader, $southVehicle))->toBeFalse()
        ->and($this->l->statistics($year, $leader)->pluck('vehicle.id')->all())->toBe([$northVehicle->id]);

    Livewire::actingAs($leader)->withQueryParams(['fahrt' => $northTrip->id, 'korrigieren' => 1])->test(TripForm::class)
        ->assertSet('mode', 'correct');
    Livewire::actingAs($leader)->withQueryParams(['fahrt' => $southTrip->id, 'korrigieren' => 1])->test(TripForm::class)
        ->assertSet('mode', 'view');

    $this->actingAs($leader)->get(route('fahrtenbuch.export.trips', [$northVehicle, $year]))->assertOk();
    $this->actingAs($leader)->get(route('fahrtenbuch.export.trips', [$southVehicle, $year]))->assertForbidden();
    $this->l->void($leader, $northTrip, 'Doppelt erfasst');
    expect(fn () => $this->l->void($leader, $southTrip, 'Doppelt erfasst'))
        ->toThrow(ValidationException::class, 'zuständige Wehrführungen');
});

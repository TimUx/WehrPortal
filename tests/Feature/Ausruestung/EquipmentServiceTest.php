<?php

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Modules\Ausruestung\src\Enums\DamageStatus;
use Modules\Ausruestung\src\Enums\EquipmentStatus;
use Modules\Ausruestung\src\Models\Category;
use Modules\Ausruestung\src\Models\Location;
use Modules\Ausruestung\src\Notifications\DamageReportedNotification;
use Modules\Ausruestung\src\Services\EquipmentService;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    activateAusruestung();
    $this->warden = ausruestungMember('geraetewart');
    $this->service = app(EquipmentService::class);
});

test('the DGUV-style categories are there after installation', function () {
    expect(Category::where('name', 'Feuerwehrhelm')->value('lifetime_years'))->toBe(10)
        ->and(Category::where('name', 'Pressluftatmer')->value('inspection_interval_months'))->toBe(12)
        ->and(Category::count())->toBeGreaterThanOrEqual(10);
});

test('items get running codes', function () {
    expect(equipment()->code)->toBe('AR-0001')->and(equipment()->code)->toBe('AR-0002')
        ->and(equipment(['code' => 'EIGEN-1'])->code)->toBe('EIGEN-1');
});

test('the service life comes from the category unless the item has its own', function () {
    $helmet = equipment(['manufactured_at' => '2014-03-01']);
    expect($helmet->lifetimeEndsAt()->toDateString())->toBe('2024-03-01')
        ->and($helmet->lifetimeExpired())->toBeTrue()
        ->and($helmet->isUsable())->toBeFalse()
        ->and($helmet->warnings())->toContain('Lebensdauer abgelaufen');

    $helmet->update(['lifetime_years' => 15]);
    expect($helmet->refresh()->lifetimeEndsAt()->toDateString())->toBe('2029-03-01')->and($helmet->lifetimeExpired())->toBeFalse();

    $noDate = equipment(['manufactured_at' => null, 'commissioned_at' => null]);
    expect($noDate->lifetimeEndsAt())->toBeNull();
});

test('a passed inspection sets the next due date from the interval', function () {
    $helmet = equipment(['next_inspection_at' => today()->subDay()]);
    expect($helmet->inspectionOverdue())->toBeTrue();

    $this->service->recordInspection($helmet, $this->warden, today(), true, 'Sichtprüfung ok');

    $helmet->refresh();
    expect($helmet->next_inspection_at->toDateString())->toBe(today()->addMonths(12)->toDateString())
        ->and($helmet->inspectionOverdue())->toBeFalse()
        ->and($helmet->inspections()->sole()->passed)->toBeTrue()
        ->and($helmet->events()->where('type', 'inspection')->exists())->toBeTrue();
});

test('a failed inspection takes the item out of service and tells the Gerätewart', function () {
    Notification::fake();
    $admin = User::factory()->admin()->create();
    $helmet = equipment();

    $this->service->recordInspection($helmet, $admin, today(), false, 'Schale gerissen');

    $helmet->refresh();
    expect($helmet->status)->toBe(EquipmentStatus::Defective)
        ->and($helmet->next_inspection_at)->toBeNull()
        ->and($helmet->damages()->sole()->description)->toContain('Schale gerissen');
    Notification::assertSentTo($this->warden, DamageReportedNotification::class);
});

test('repairs bring the item back only when the last damage is done', function () {
    Notification::fake();
    $helmet = equipment();
    $first = $this->service->reportDamage($helmet, $this->warden, 'Visier zerkratzt');
    $second = $this->service->reportDamage($helmet->refresh(), $this->warden, 'Nackenschutz lose');
    expect($helmet->refresh()->status)->toBe(EquipmentStatus::Defective);

    $this->service->updateDamage($first, $this->warden, DamageStatus::InRepair);
    expect($helmet->refresh()->status)->toBe(EquipmentStatus::InRepair);

    $this->service->updateDamage($first->refresh(), $this->warden, DamageStatus::Repaired, 'Visier getauscht', 45.5);
    expect($helmet->refresh()->status)->toBe(EquipmentStatus::InRepair)
        ->and((float) $first->refresh()->repair_cost)->toBe(45.5);

    $this->service->updateDamage($second, $this->warden, DamageStatus::Repaired);
    expect($helmet->refresh()->status)->toBe(EquipmentStatus::Available);
});

test('a scrapped damage retires the item', function () {
    Notification::fake();
    $helmet = equipment();
    $damage = $this->service->reportDamage($helmet, $this->warden, 'Totalschaden');

    $this->service->updateDamage($damage, $this->warden, DamageStatus::Scrapped);

    expect($helmet->refresh()->status)->toBe(EquipmentStatus::Retired);
});

test('moving an item is logged and a compartment sets its location', function () {
    $vehicle = Location::create(['name' => 'HLF 20', 'kind' => 'vehicle']);
    $g1 = $vehicle->compartments()->create(['name' => 'G1']);
    $helmet = equipment();

    $this->service->move($helmet, $this->warden, null, $g1->id);

    expect($helmet->refresh()->location_id)->toBe($vehicle->id)
        ->and($helmet->placeLabel())->toBe('HLF 20 › G1')
        ->and($helmet->events()->where('type', 'move')->sole()->body)->toContain('HLF 20 › G1');
});

test('Einsatz and Übung are kept as history', function () {
    $helmet = equipment();

    $this->service->log($helmet, $this->warden, 'operation', 'Brand Scheune', now()->subDay());

    expect($helmet->events()->where('type', 'operation')->sole())->body->toBe('Brand Scheune');
});

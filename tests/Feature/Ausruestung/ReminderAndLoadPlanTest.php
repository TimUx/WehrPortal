<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Modules\Ausruestung\src\Enums\EquipmentStatus;
use Modules\Ausruestung\src\Models\Category;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Ausruestung\src\Models\Location;
use Modules\Ausruestung\src\Notifications\LoanOverdueNotification;
use Modules\Ausruestung\src\Notifications\MaintenanceDigestNotification;
use Modules\Ausruestung\src\Services\InventoryExport;
use Modules\Ausruestung\src\Services\LoadPlanService;
use Modules\Ausruestung\src\Services\LoanService;
use Modules\Ausruestung\src\Services\ReminderService;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    activateAusruestung();
    Notification::fake();
    Cache::flush();
    $this->warden = ausruestungMember('geraetewart');
    $this->member = ausruestungMember();
});

// ─── reminders ───────────────────────────────────────────────────────────────

test('the Gerätewart gets one digest a day when something is due', function () {
    equipment(['next_inspection_at' => today()->addDays(10)]);

    expect(app(ReminderService::class)->sendDigest())->toBeTrue();
    expect(app(ReminderService::class)->sendDigest())->toBeFalse();

    Notification::assertSentToTimes($this->warden, MaintenanceDigestNotification::class, 1);
});

test('no digest when nothing is due', function () {
    equipment(['next_inspection_at' => today()->addYear(), 'manufactured_at' => today()]);

    expect(app(ReminderService::class)->sendDigest())->toBeFalse();
    Notification::assertNothingSent();
});

test('the summary lists due inspections, ending service lives and overdue loans', function () {
    $due = equipment(['next_inspection_at' => today()->subDay()]);
    $old = equipment(['manufactured_at' => today()->subYears(10)->addDays(30), 'next_inspection_at' => today()->addYear()]);
    $fine = equipment(['next_inspection_at' => today()->addYear(), 'manufactured_at' => today()]);
    $loan = app(LoanService::class)->lend($fine, $this->member, $this->warden, now()->addHour());
    $loan->update(['due_at' => now()->subHour()]);

    $summary = app(ReminderService::class)->summary();

    expect($summary['inspections']->pluck('id')->all())->toBe([$due->id])
        ->and($summary['lifetime']->pluck('id')->all())->toBe([$old->id])
        ->and($summary['overdue_loans']->pluck('id')->all())->toBe([$loan->id]);
});

test('an overdue borrower is reminded once', function () {
    $loan = app(LoanService::class)->lend(equipment(), $this->member, $this->warden, now()->addHour());
    $loan->update(['due_at' => now()->subMinute()]);

    expect(app(ReminderService::class)->remindOverdueLoans())->toBe(1)
        ->and(app(ReminderService::class)->remindOverdueLoans())->toBe(0);
    Notification::assertSentToTimes($this->member, LoanOverdueNotification::class, 1);
});

test('the reminder command runs', function () {
    $this->artisan('ausruestung:send-reminders')->assertSuccessful();
});

// ─── Beladeplan ──────────────────────────────────────────────────────────────

test('the load plan compares target and actual per compartment', function () {
    $helmets = Category::where('name', 'Feuerwehrhelm')->first();
    $leash = Category::where('name', 'Feuerwehrleine')->first();
    $hlf = Location::create(['name' => 'HLF 20', 'kind' => 'vehicle']);
    $g1 = $hlf->compartments()->create(['name' => 'G1']);
    $g1->loadPlanItems()->create(['category_id' => $helmets->id, 'quantity' => 3]);
    $g1->loadPlanItems()->create(['category_id' => $leash->id, 'quantity' => 1]);

    equipment(['compartment_id' => $g1->id]);
    equipment(['compartment_id' => $g1->id, 'next_inspection_at' => today()->subDay()]); // present, not usable
    $lent = equipment(['compartment_id' => $g1->id]);
    app(LoanService::class)->lend($lent, $this->member, $this->warden, now()->addDay()); // away, not on the vehicle
    equipment(['compartment_id' => $g1->id, 'name' => 'Wärmebildkamera', 'category_id' => Category::where('name', 'Elektrisches Gerät')->value('id')]);
    equipment(['location_id' => $hlf->id, 'name' => 'Lose Lampe']);

    $service = app(LoadPlanService::class);
    $block = $service->compare($hlf)->first();
    $helmetRow = $block['rows']->firstWhere('category', 'Feuerwehrhelm');
    $leashRow = $block['rows']->firstWhere('category', 'Feuerwehrleine');

    expect($helmetRow)->toMatchArray(['target' => 3, 'present' => 2, 'usable' => 1, 'missing' => 2])
        ->and($helmetRow['problems'][0])->toContain('Prüfung überfällig')
        ->and($leashRow)->toMatchArray(['target' => 1, 'present' => 0, 'missing' => 1])
        ->and($block['unplanned']->pluck('name')->all())->toBe(['Wärmebildkamera'])
        ->and($service->unassigned($hlf)->pluck('name')->all())->toBe(['Lose Lampe'])
        ->and($service->missingCount($hlf))->toBe(3);
});

// ─── Export ──────────────────────────────────────────────────────────────────

test('the inventory export has German columns and the item data', function () {
    $item = equipment(['purchase_price' => 289.9, 'serial_number' => 'SN-42']);

    $row = app(InventoryExport::class)->row($item->refresh());

    expect($row[0])->toBe($item->code)
        ->and($row)->toContain('SN-42', 'Feuerwehrhelm', 'Einsatzbereit', '289,90');

    $response = app(InventoryExport::class)->download(Equipment::query(), 'inventur.csv');
    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();

    expect($csv)->toStartWith("\xEF\xBB\xBF")->toContain('Code;Bezeichnung;Kategorie')->toContain('SN-42');
});

test('retired items show up in the export with their status', function () {
    $item = equipment(['status' => EquipmentStatus::Retired]);

    expect(app(InventoryExport::class)->row($item))->toContain('Ausgesondert');
});

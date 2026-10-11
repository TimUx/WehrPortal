<?php

use App\Models\Module;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\Ausruestung\src\Enums\EquipmentStatus;
use Modules\Ausruestung\src\Enums\LoanStatus;
use Modules\Ausruestung\src\Filament\Pages\LoadPlanPage;
use Modules\Ausruestung\src\Filament\Resources\CategoryResource\Pages\ManageCategories;
use Modules\Ausruestung\src\Filament\Resources\DamageResource\Pages\ListDamages;
use Modules\Ausruestung\src\Filament\Resources\EquipmentResource\Pages\CreateEquipment;
use Modules\Ausruestung\src\Filament\Resources\EquipmentResource\Pages\EditEquipment;
use Modules\Ausruestung\src\Filament\Resources\EquipmentResource\Pages\ListEquipment;
use Modules\Ausruestung\src\Filament\Resources\LoanResource\Pages\ListLoans;
use Modules\Ausruestung\src\Filament\Resources\LocationResource\Pages\EditLocation;
use Modules\Ausruestung\src\Filament\Resources\LocationResource\RelationManagers\CompartmentsRelationManager;
use Modules\Ausruestung\src\Filament\Resources\RecallResource\Pages\CreateRecall;
use Modules\Ausruestung\src\Filament\Resources\RecallResource\Pages\EditRecall;
use Modules\Ausruestung\src\Filament\Resources\RecallResource\RelationManagers\AffectedEquipmentRelationManager;
use Modules\Ausruestung\src\Livewire\EquipmentDetail;
use Modules\Ausruestung\src\Livewire\MyLoans;
use Modules\Ausruestung\src\Models\Category;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Ausruestung\src\Models\Loan;
use Modules\Ausruestung\src\Models\Location;
use Modules\Ausruestung\src\Models\Recall;
use Modules\Ausruestung\src\Services\EquipmentService;
use Modules\Ausruestung\src\Services\LoanService;
use Modules\Ausruestung\src\Services\RecallService;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    activateAusruestung();
    Notification::fake();
    $this->warden = ausruestungMember('geraetewart');
    $this->member = ausruestungMember(attributes: ['name' => 'Hanna Hydrant']);
    $this->helmet = equipment();
});

// ─── access ──────────────────────────────────────────────────────────────────

test('the module is hidden while inactive and needs a grant', function () {
    $this->actingAs($this->member)->get('/ausruestung')->assertOk()->assertSee('Meine Ausleihen');
    $this->actingAs(User::factory()->create())->get('/ausruestung')->assertForbidden();

    Module::where('key', 'ausruestung')->update(['is_active' => false]);
    Cache::forget('modules_active_keys');
    $this->actingAs($this->member)->get('/ausruestung')->assertNotFound();
});

test('only Gerätewarte and admins reach the admin area', function () {
    $this->actingAs($this->member)->get('/admin/ausruestung')->assertForbidden();
    $this->actingAs($this->warden)->get('/admin/ausruestung')->assertOk()->assertSee('Geräte im Bestand');
    $this->actingAs(User::factory()->admin()->create())->get('/admin/ausruestung')->assertOk();
});

// ─── member pages ────────────────────────────────────────────────────────────

test('members see the equipment list and an item', function () {
    $this->actingAs($this->member)->get('/ausruestung/geraete')->assertOk()->assertSee('Feuerwehrhelm HPS 7000');
    $this->actingAs($this->member)->get('/ausruestung/geraete/'.$this->helmet->id)->assertOk()->assertSee($this->helmet->code)->assertSee('Schaden melden');
});

test('a member reports a damage, reserves and logs an Einsatz', function () {
    $component = Livewire::actingAs($this->member)->test(EquipmentDetail::class, ['equipment' => $this->helmet]);

    $component->set('damage', 'Visier gebrochen')->call('reportDamage')->assertHasNoErrors();
    expect($this->helmet->refresh()->status)->toBe(EquipmentStatus::Defective);

    $other = equipment(['name' => 'Wathose']);
    Livewire::actingAs($this->member)->test(EquipmentDetail::class, ['equipment' => $other])
        ->set('reserveFrom', now()->addDay()->setTimezone('Europe/Berlin')->format('Y-m-d\T08:00'))
        ->set('reserveUntil', now()->addDay()->setTimezone('Europe/Berlin')->format('Y-m-d\T18:00'))
        ->call('reserve')
        ->assertHasNoErrors()
        ->set('useType', 'exercise')->set('useText', 'Übung Wasserförderung')->call('logUse')
        ->assertHasNoErrors();

    expect(Loan::where('user_id', $this->member->id)->sole()->status)->toBe(LoanStatus::Reserved)
        ->and($other->events()->where('type', 'exercise')->sole()->body)->toBe('Übung Wasserförderung');
});

test('a member cannot reserve into a taken period', function () {
    app(LoanService::class)->reserve($this->helmet, $this->warden, $this->warden, now()->addDay()->setTime(6, 0), now()->addDays(3));

    Livewire::actingAs($this->member)->test(EquipmentDetail::class, ['equipment' => $this->helmet])
        ->set('reserveFrom', now()->addDays(2)->setTimezone('Europe/Berlin')->format('Y-m-d\T08:00'))
        ->set('reserveUntil', now()->addDays(2)->setTimezone('Europe/Berlin')->format('Y-m-d\T18:00'))
        ->call('reserve')
        ->assertHasErrors('reserveFrom');
});

test('members cancel only their own reservations', function () {
    $mine = app(LoanService::class)->reserve($this->helmet, $this->member, $this->member, now()->addDay(), now()->addDays(2));
    $theirs = app(LoanService::class)->reserve(equipment(), $this->warden, $this->warden, now()->addDay(), now()->addDays(2));

    Livewire::actingAs($this->member)->test(MyLoans::class)->assertSee($this->helmet->name)->call('cancel', $mine->id);
    expect($mine->refresh()->status)->toBe(LoanStatus::Cancelled);

    Livewire::actingAs($this->member)->test(MyLoans::class)->call('cancel', $theirs->id)->assertNotFound();
    expect($theirs->refresh()->status)->toBe(LoanStatus::Reserved);
});

// ─── scanning ────────────────────────────────────────────────────────────────

test('a scan opens the item: in the admin area for Gerätewarte, in the app for members', function () {
    $this->actingAs($this->warden)->get('/i/'.$this->helmet->code)
        ->assertRedirect(url('/admin/ausruestung/geraete/'.$this->helmet->id.'/bearbeiten'));

    $this->actingAs($this->member)->get('/i/'.$this->helmet->code)
        ->assertRedirect(route('ausruestung.show', $this->helmet));
});

test('the manufacturer barcode works like the own code', function () {
    $this->helmet->update(['barcode' => '4012345678901']);

    $this->actingAs($this->member)->get('/i/4012345678901')->assertRedirect(route('ausruestung.show', $this->helmet));
});

// ─── admin area ──────────────────────────────────────────────────────────────

test('the admin pages render', function () {
    Filament::setCurrentPanel(Filament::getPanel('ausruestung'));
    $this->actingAs($this->warden);

    Livewire::test(ListEquipment::class)->assertCanSeeTableRecords([$this->helmet]);
    Livewire::test(EditEquipment::class, ['record' => $this->helmet->getKey()])->assertOk();
    Livewire::test(ListLoans::class)->assertOk();
    Livewire::test(ListDamages::class)->assertOk();
    Livewire::test(ManageCategories::class)->assertSee('Feuerwehrhelm');
    Livewire::test(LoadPlanPage::class)->assertOk();
});

test('a Gerätewart creates an item, records an inspection and lends it from the list', function () {
    Filament::setCurrentPanel(Filament::getPanel('ausruestung'));
    $this->actingAs($this->warden);

    Livewire::test(CreateEquipment::class)
        ->fillForm(['name' => 'Pressluftatmer PSS 7000', 'category_id' => Category::where('name', 'Pressluftatmer')->value('id'), 'manufacturer' => 'Dräger'])
        ->call('create')
        ->assertHasNoFormErrors();
    $sca = Equipment::where('name', 'Pressluftatmer PSS 7000')->sole();

    Livewire::test(ListEquipment::class)
        ->callTableAction('inspect', $sca, data: ['inspected_at' => today()->toDateString(), 'passed' => true])
        ->callTableAction('lend', $sca, data: ['user_id' => $this->member->id, 'due_at' => now()->addWeek()->toDateTimeString()]);

    expect($sca->refresh()->next_inspection_at->toDateString())->toBe(today()->addMonths(12)->toDateString())
        ->and($sca->status)->toBe(EquipmentStatus::Loaned);
});

test('the list filters items whose inspection is due', function () {
    Filament::setCurrentPanel(Filament::getPanel('ausruestung'));
    $due = equipment(['next_inspection_at' => today()->addDays(5)]);

    Livewire::actingAs($this->warden)->test(ListEquipment::class)
        ->filterTable('inspection_due')
        ->assertCanSeeTableRecords([$due])
        ->assertCanNotSeeTableRecords([$this->helmet]);
});

test('the export downloads the filtered list as CSV', function () {
    Filament::setCurrentPanel(Filament::getPanel('ausruestung'));

    Livewire::actingAs($this->warden)->test(ListEquipment::class)
        ->callAction('export')
        ->assertFileDownloaded('ausruestung-inventur-'.now()->format('Y-m-d').'.csv');
});

test('creating a recall locks the matching items at once', function () {
    Filament::setCurrentPanel(Filament::getPanel('ausruestung'));

    Livewire::actingAs($this->warden)->test(CreateRecall::class)
        ->fillForm(['title' => 'Kinnriemen', 'manufacturer' => 'Dräger', 'model' => 'HPS 7000'])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('1 Gerät(e) gefunden und gesperrt');

    expect($this->helmet->refresh()->status)->toBe(EquipmentStatus::Locked);
});

test('every sub-list of an item, a recall and a location renders', function () {
    Filament::setCurrentPanel(Filament::getPanel('ausruestung'));
    $this->actingAs($this->warden);
    app(LoanService::class)->lend($this->helmet, $this->member, $this->warden, now()->addDay());
    app(EquipmentService::class)->recordInspection($this->helmet, $this->warden, today(), true);
    $recall = Recall::create(['title' => 'Test', 'manufacturer' => 'Dräger', 'model' => 'HPS 7000']);
    app(RecallService::class)->apply($recall);
    $hlf = Location::create(['name' => 'HLF 20', 'kind' => 'vehicle']);
    $hlf->compartments()->create(['name' => 'G1'])->loadPlanItems()->create(['category_id' => $this->helmet->category_id, 'quantity' => 2]);

    foreach (['EventsRelationManager', 'InspectionsRelationManager', 'LoansRelationManager', 'DamagesRelationManager', 'RecallsRelationManager'] as $manager) {
        Livewire::test("Modules\\Ausruestung\\src\\Filament\\Resources\\EquipmentResource\\RelationManagers\\{$manager}", ['ownerRecord' => $this->helmet->refresh(), 'pageClass' => EditEquipment::class])
            ->assertOk();
    }

    Livewire::test(AffectedEquipmentRelationManager::class, ['ownerRecord' => $recall, 'pageClass' => EditRecall::class])
        ->assertCanSeeTableRecords([$this->helmet]);
    Livewire::test(CompartmentsRelationManager::class, ['ownerRecord' => $hlf, 'pageClass' => EditLocation::class])
        ->assertSee('2× Feuerwehrhelm');
    Livewire::test(LoadPlanPage::class, ['location' => $hlf->id])->assertSee('G1')->assertSee('fehlen');

    foreach (['/admin/ausruestung/geraete', '/admin/ausruestung/geraete/'.$this->helmet->id.'/bearbeiten', '/admin/ausruestung/ausleihe', '/admin/ausruestung/schaeden', '/admin/ausruestung/rueckrufe', '/admin/ausruestung/rueckrufe/'.$recall->id.'/bearbeiten', '/admin/ausruestung/kategorien', '/admin/ausruestung/standorte', '/admin/ausruestung/standorte/'.$hlf->id.'/bearbeiten', '/admin/ausruestung/beladeplan'] as $url) {
        $this->get($url)->assertOk();
    }
});

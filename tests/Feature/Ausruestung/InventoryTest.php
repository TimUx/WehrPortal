<?php

use App\Livewire\Inventory\Search;
use App\Models\Module;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Ausruestung\src\Enums\EquipmentStatus;
use Modules\Ausruestung\src\Livewire\EquipmentDetail;
use Modules\Ausruestung\src\Livewire\StocktakeForm;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Ausruestung\src\Models\Location;
use Modules\Ausruestung\src\Models\Stocktake;
use Modules\Ausruestung\src\Services\EquipmentService;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeuge\src\Models\Profile;
use Modules\Fahrzeuge\src\Services\FleetService;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Maengelmanagement\src\Models\Device;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    config(['locations.enabled' => false]);
    activateAusruestung();
    Module::whereIn('key', ['fahrzeuge', 'kleiderkammer', 'maengelmanagement'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Notification::fake();

    $this->warden = ausruestungMember('geraetewart', ['name' => 'Gerd Gerätewart']);
    $this->warden->moduleAccess()->create(['module' => 'fahrzeuge', 'role' => 'fahrzeugwart']);
    $this->hanna = ausruestungMember(null, ['name' => 'Hanna Hydrant']);
    $this->hanna->moduleAccess()->create(['module' => 'fahrzeuge']);

    $this->room = Location::create(['name' => 'Lager Gerätehaus', 'kind' => 'room']);
    $this->hlfLocation = Location::create(['name' => 'HLF 20', 'kind' => 'vehicle']);
    $this->helmet = equipment(['location_id' => $this->hlfLocation->id, 'purchase_price' => 320, 'manufactured_at' => now()->subYears(8)->toDateString()]);
    $this->lamp = equipment(['name' => 'Handlampe', 'code' => 'AR-LAMP', 'serial_number' => '77', 'category_id' => null, 'location_id' => $this->hlfLocation->id]);
    $this->saw = equipment(['name' => 'Motorkettensäge', 'serial_number' => '88', 'category_id' => null, 'location_id' => $this->room->id]);
    $this->service = app(EquipmentService::class);
});

test('an RFID/NFC tag identifies an item like its code: Bestand, scan and Geräteinfo', function () {
    Livewire::actingAs($this->warden)->test(EquipmentDetail::class, ['equipment' => $this->lamp])
        ->set('tag', '04:a2:3b:9c:11:22:80')->call('saveTag')->assertHasNoErrors()->assertSee('Tag 04A23B9C112280 zugeordnet');
    expect($this->lamp->refresh()->rfid)->toBe('04A23B9C112280')
        ->and(Equipment::findByScan('04:A2:3B:9C:11:22:80')?->id)->toBe($this->lamp->id);

    // One tag, one item; only Gerätewarte assign
    expect(fn () => $this->service->setTag($this->saw, $this->warden, '04a23b9c112280'))->toThrow(ValidationException::class);
    expect(fn () => $this->service->setTag($this->saw, $this->hanna, 'AABB'))->toThrow(ValidationException::class);

    // The NFC serial (with colons) in the Bestand field opens the item
    Livewire::actingAs($this->hanna)->test(Search::class)->set('q', '04:a2:3b:9c:11:22:80')->call('lookup')
        ->assertRedirect(route('ausruestung.show', $this->lamp));
    $this->actingAs($this->hanna)->get(route('scan.code', '04A23B9C112280'))->assertRedirect(route('ausruestung.show', $this->lamp));

    // Unknown code: not offered to the Kleiderkammer fallback, just "not found"
    Livewire::actingAs($this->hanna)->test(Search::class)->set('q', 'XYZ-UNBEKANNT')->call('lookup')->assertNoRedirect()->assertSee('ist nirgends im Bestand');
});

test('the Bestand searches vehicles, Geräte, Kleidung and Mängel objects – each for who may see it', function () {
    $hlf = Vehicle::factory()->create(['name' => 'HLF 20', 'is_active' => true]);
    app(FleetService::class)->saveVehicle($this->warden, $hlf, ['name' => 'HLF 20', 'color' => '#dc2626', 'license_plate' => 'MU-FW 112']);
    $type = ItemType::create(['name' => 'Einsatzjacke HLF', 'code_prefix' => 'EJA', 'is_tracked' => true, 'has_size' => false, 'is_washable' => false, 'sort_order' => 1]);
    Item::create(['item_type_id' => $type->id, 'code' => 'WEHREJA0001', 'status' => 'available']);
    $this->warden->moduleAccess()->create(['module' => 'kleiderkammer', 'role' => 'kleiderwart']);
    $this->hanna->moduleAccess()->create(['module' => 'maengelmanagement']);
    Device::create(['name' => 'Tor 2 HLF-Halle', 'location' => 'Fahrzeughalle']);

    Livewire::actingAs($this->hanna)->test(Search::class)->set('q', 'HLF')
        ->assertSee('Fahrzeuge')->assertSee('MU-FW 112')->assertSee('Einsatzbereit')
        ->assertSee('Tor 2 HLF-Halle')->assertSee('keine Mängel')
        ->assertDontSee('Einsatzjacke HLF');
    Livewire::actingAs($this->hanna)->test(Search::class)->set('q', 'helm')->assertSee('Feuerwehrhelm HPS 7000')->assertSee('HLF 20');

    Livewire::actingAs($this->warden)->test(Search::class)->set('q', 'Einsatzjacke')->assertSee('Kleiderkammer')->assertSee('WEHREJA0001')->assertSee('Verfügbar');
    $this->actingAs($this->hanna)->get(route('inventory'))->assertOk()->assertSee('Bestand');
});

test('Inventur: scanned and ticked items, missing ones, items found elsewhere are moved here', function () {
    $this->service->setTag($this->lamp, $this->warden, '0411AA');
    $loaned = equipment(['name' => 'Wärmebildkamera', 'serial_number' => '99', 'category_id' => null, 'location_id' => $this->hlfLocation->id, 'status' => EquipmentStatus::Loaned]);

    Livewire::actingAs($this->warden)->test(StocktakeForm::class)
        ->set('locationId', (string) $this->hlfLocation->id)
        ->set('code', '04:11:aa')->call('scan')->assertSee('✓ Handlampe')
        ->set('code', $this->saw->code)->call('scan')->assertSee('Gefunden, aber woanders gebucht')->assertSee('Lager Gerätehaus')
        ->set('code', 'NIX-123')->call('scan')->assertSee('Unbekannte Codes: NIX-123')
        ->assertSee('von 3 gefunden')->assertSee('ausgeliehen')
        ->call('finish')->assertSee('Inventur HLF 20 abgeschlossen: 2 gefunden, 1 fehlen, 1 hierher umgebucht')->assertSee('Fehlt: Feuerwehrhelm HPS 7000');

    $stocktake = Stocktake::sole();
    expect($stocktake->expected)->toBe(3)->and($stocktake->unknown)->toBe(['NIX-123'])
        ->and(collect($stocktake->missing)->pluck('id')->all())->toBe([$this->helmet->id])
        ->and($this->saw->refresh()->location_id)->toBe($this->hlfLocation->id)
        ->and($this->lamp->refresh()->last_seen_at)->not->toBeNull()
        ->and($this->helmet->events()->where('type', 'inventory')->value('body'))->toContain('nicht gefunden')
        ->and($loaned->events()->where('type', 'inventory')->exists())->toBeFalse();

    $this->actingAs($this->hanna)->get(route('ausruestung.stocktake'))->assertForbidden();
    $this->actingAs($this->warden)->get(route('ausruestung.stocktake'))->assertOk();
});

test('Reinigung and Wartung with costs make up the Lebenszykluskosten', function () {
    Livewire::actingAs($this->warden)->test(EquipmentDetail::class, ['equipment' => $this->helmet])
        ->set('workType', 'cleaning')->set('workText', 'nach Brandeinsatz gewaschen')->set('workCost', '35,00')->call('logWork')->assertHasNoErrors()
        ->assertSee('Reinigung eingetragen')->assertSee('35,00 €');
    $this->helmet->damages()->create(['description' => 'Visier gebrochen', 'status' => 'repaired', 'repair_cost' => 60]);

    expect($this->service->lifecycleCosts($this->helmet))->toBe(['purchase' => 320.0, 'repairs' => 60.0, 'work' => 35.0, 'total' => 415.0]);
    Livewire::actingAs($this->warden)->test(EquipmentDetail::class, ['equipment' => $this->helmet])->assertSee('415,00 €');
    Livewire::actingAs($this->hanna)->test(EquipmentDetail::class, ['equipment' => $this->helmet])->assertDontSee('Kosten über die Lebensdauer')->assertDontSee('Reinigung oder Wartung');
    expect(fn () => $this->service->logWork($this->helmet, $this->hanna, 'cleaning', null, now(), null))->toThrow(ValidationException::class);
});

test('Ersatzbeschaffung: Geräte by their Lebensdauer and vehicles by plan, for Gerätewarte and Fahrzeugwarte', function () {
    $hlf = Vehicle::factory()->create(['name' => 'HLF 20', 'is_active' => true]);
    app(FleetService::class)->saveVehicle($this->warden, $hlf, ['name' => 'HLF 20', 'color' => '#dc2626', 'purchased_on' => '2008-04-01',
        'replacement_year' => (string) (now()->year + 3), 'replacement_cost' => '450.000,00']);
    expect(Profile::where('vehicle_id', $hlf->id)->value('replacement_cost_cents'))->toBe(45000000);

    $year = (int) now('Europe/Berlin')->year;
    Livewire::actingAs($this->warden)->test(Search::class)->set('tab', 'ersatz')
        ->assertSee('Ersatzbeschaffung')->assertSee((string) ($year + 2))->assertSee('Feuerwehrhelm HPS 7000')->assertSee('320,00')
        ->assertSee((string) ($year + 3))->assertSee('450.000,00')->assertSee('beschafft 2008');

    // Kameraden only search
    Livewire::actingAs($this->hanna)->test(Search::class)->set('tab', 'ersatz')->assertDontSee('450.000,00')->assertDontSee('Ersatzbeschaffung');
});

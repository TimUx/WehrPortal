<?php

use App\Models\Module;
use App\Models\User;
use App\Services\ModuleRegistry;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\ClothingStockService;
use Extensions\Locations\Services\LocationModuleRoles;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Kleiderkammer\src\Filament\Pages\DataTransferPage;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource\Pages\EditMember;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource\RelationManagers\IssuancesRelationManager;
use Modules\Kleiderkammer\src\Filament\Resources\StockResource\Pages\CreateStock;
use Modules\Kleiderkammer\src\Filament\Resources\StockResource\Pages\EditStock;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Models\ItemGroup;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\Size;
use Modules\Kleiderkammer\src\Models\Stock;
use Modules\Kleiderkammer\src\Services\DataTransferService;
use Modules\Kleiderkammer\src\Services\IssuingService;
use Modules\Kleiderkammer\src\Services\PersonalAccess;
use Modules\Kleiderkammer\src\Services\StockAccess;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config(['locations.clothing_stock_enabled' => false, 'locations.clothing_personal_enabled' => false]);
    Module::where('key', 'kleiderkammer')->update(['is_active' => true, 'auto_assign' => true]);
    app(ModuleRegistry::class)->clearCache();
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->a = Location::create(['name' => 'Wache Nord', 'slug' => 'warehouse-north']);
    $this->b = Location::create(['name' => 'Wache Süd', 'slug' => 'warehouse-south']);
    $this->member = User::factory()->create(['ext_location_id' => $this->a->id]);
    $this->type = ItemType::create(['name' => 'Handschuhe', 'is_tracked' => false]);
    $this->size = Size::create(['item_type_id' => $this->type->id, 'name' => 'M']);
    $this->group = ItemGroup::create(['item_type_id' => $this->type->id, 'name' => 'Einsatz']);
    $this->keys = ['item_type_id' => $this->type->id, 'size_id' => $this->size->id, 'group_id' => $this->group->id];
    $this->makeStock = function (?int $location, int $quantity = 10) {
        $stock = (new Stock([...$this->keys, 'quantity' => $quantity]))->forceFill(['ext_location_id' => $location]);
        $stock->save();

        return $stock;
    };
    $this->north = ($this->makeStock)($this->a->id);
    $this->south = ($this->makeStock)($this->b->id, 20);
    $this->service = app(IssuingService::class);
    $this->issue = fn (?int $id = null, ?User $actor = null) => $this->service->issueStock(
        $this->member, $this->type, $this->size, 2, group: $this->group, stockId: $id, actor: $actor);
    $this->history = fn () => IssuedItem::create([
        ...$this->keys, 'user_id' => $this->member->id, 'quantity' => 3, 'issued_at' => now(),
    ]);
    $this->migration = require base_path('extensions/Locations/database/migrations/2026_12_29_000000_scope_clothing_stock_uniqueness_to_location.php');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('keeps shared catalogs and separate warehouse quantities with null-safe same-site uniqueness', function (?string $nullable) {
    $keys = $this->keys;
    if ($nullable !== null) {
        $keys[$nullable] = null;
    }
    DB::table('kk_stock')->delete();
    foreach ([$this->a->id, $this->b->id, null] as $location) {
        DB::table('kk_stock')->insert([...$keys, 'quantity' => 7, 'ext_location_id' => $location]);
        expect(fn () => DB::transaction(fn () => DB::table('kk_stock')->insert([
            ...$keys, 'quantity' => 99, 'ext_location_id' => $location,
        ])))->toThrow(QueryException::class);
    }
    expect(Stock::count())->toBe(3)->and(Stock::sum('quantity'))->toBe(21)
        ->and(ItemType::count())->toBe(1)->and(Size::count())->toBe(1)->and(ItemGroup::count())->toBe(1);
})->with([null, 'size_id', 'group_id']);

it('requires explicit source across identical catalog rows even when flags are disabled', function (bool $enabled) {
    config(['locations.enabled' => $enabled]);
    expect(fn () => ($this->issue)())->toThrow(DomainException::class);
    expect(IssuedItem::count())->toBe(0)->and(Stock::sum('quantity'))->toBe(30);
    $issued = ($this->issue)($this->south->id);
    expect($issued->getAttribute('ext_source_stock_id'))->toBe($this->south->id)
        ->and($this->north->fresh()->quantity)->toBe(10)->and($this->south->fresh()->quantity)->toBe(18);
    $this->service->return($issued);
    expect(Stock::sum('quantity'))->toBe(30);
})->with([true, false]);

it('allows a truly sole stock legacy issue but never accepts a stale explicit id', function () {
    $this->south->delete();
    $issued = ($this->issue)();
    expect($issued->getAttribute('ext_source_stock_id'))->toBe($this->north->id);
    expect(fn () => ($this->issue)($this->south->id))->toThrow(DomainException::class);
    expect(IssuedItem::count())->toBe(1)->and($this->north->fresh()->quantity)->toBe(8);
});

it('returns bound stock after catalog edits and all adapter flags are disabled', function () {
    $issued = ($this->issue)($this->south->id);
    $other = ItemType::create(['name' => 'Gürtel', 'is_tracked' => false]);
    $this->south->update(['item_type_id' => $other->id, 'size_id' => null, 'group_id' => null]);
    config(['locations.enabled' => false, 'locations.clothing_stock_enabled' => false, 'locations.clothing_personal_enabled' => false]);
    $this->service->return($issued, $this->admin);
    expect($this->south->fresh()->quantity)->toBe(20)->and($this->north->fresh()->quantity)->toBe(10)
        ->and($issued->fresh()->item_type_id)->toBe($this->type->id);
});

it('refuses historical ambiguous returns atomically even for global admins', function () {
    $legacy = ($this->history)();
    expect(fn () => $this->service->return($legacy, $this->admin))->toThrow(DomainException::class);
    expect($legacy->fresh()->returned_at)->toBeNull()->and(Stock::sum('quantity'))->toBe(30);
});

it('recreates only unowned legacy stock when no return candidate exists and adapters are disabled', function (bool $locationsEnabled) {
    $legacy = ($this->history)();
    DB::table('kk_stock')->delete();
    config(['locations.enabled' => $locationsEnabled]);
    $this->service->return($legacy, $this->admin);
    $stock = Stock::sole();
    expect($stock->quantity)->toBe(3)->and($stock->getAttribute('ext_location_id'))->toBeNull()
        ->and($legacy->fresh()->returned_at)->not->toBeNull()
        ->and($legacy->fresh()->getAttribute('ext_source_stock_id'))->toBeNull();
})->with([false, true]);

it('does not invent a location to recreate missing stock in protected mode', function () {
    $legacy = ($this->history)();
    DB::table('kk_stock')->delete();
    $this->actingAs($this->admin);
    config(['locations.clothing_stock_enabled' => true]);
    expect(fn () => $this->service->return($legacy, $this->admin))->toThrow(ValidationException::class);
    expect(Stock::count())->toBe(0)->and($legacy->fresh()->returned_at)->toBeNull();
});

it('rolls back earlier takeBack returns when a later historical warehouse is ambiguous', function () {
    $bound = ($this->issue)($this->north->id);
    $legacy = ($this->history)();
    expect(fn () => $this->service->takeBack($this->member, $this->admin))->toThrow(DomainException::class);
    expect($bound->fresh()->returned_at)->toBeNull()->and($legacy->fresh()->returned_at)->toBeNull()
        ->and(Stock::sum('quantity'))->toBe(28);
});

it('keeps the legacy return sole-candidate fallback without inferring member location', function () {
    $legacy = ($this->history)();
    $this->north->delete();
    $this->service->return($legacy, $this->admin);
    expect($this->south->fresh()->quantity)->toBe(23)->and($legacy->fresh()->returned_at)->not->toBeNull();
});

it('refuses destructive migration rollback and preserves all stock and issuance history', function () {
    $issued = ($this->issue)($this->north->id);
    expect(fn () => DB::transaction(fn () => $this->migration->down()))->toThrow(RuntimeException::class);
    expect(Stock::sum('quantity'))->toBe(28)->and(Stock::count())->toBe(2)
        ->and($issued->fresh()->getAttribute('ext_source_stock_id'))->toBe($this->north->id);
});

it('upgrades a preexisting owned stock without changing quantity location or historical provenance', function () {
    $legacy = ($this->history)();
    $this->south->delete();
    $this->migration->down();
    $this->migration->up();
    expect($this->north->fresh()->quantity)->toBe(10)
        ->and($this->north->fresh()->getAttribute('ext_location_id'))->toBe($this->a->id)
        ->and($legacy->fresh()->getAttribute('ext_source_stock_id'))->toBeNull()
        ->and($legacy->fresh()->returned_at)->toBeNull();
});

it('serializes source selection against a concurrent warehouse insertion', function () {
    $this->south->delete();
    $blocked = false;
    IssuedItem::creating(function () use (&$blocked) {
        $other = DB::build(config('database.connections.pgsql'));
        try {
            $other->transaction(function () use ($other) {
                $other->statement("SET LOCAL lock_timeout = '100ms'");
                $other->table('kk_stock')->insert([...$this->keys, 'ext_location_id' => $this->b->id, 'quantity' => 1]);
            });
        } catch (QueryException $exception) {
            $blocked = str_contains($exception->getMessage(), 'lock timeout');
        } finally {
            $other->disconnect();
        }
    });
    ($this->issue)();
    expect($blocked)->toBeTrue()->and(Stock::count())->toBe(1);
});

it('fails stock transfer closed for warehouse ownership including when flags are off', function () {
    $transfer = app(DataTransferService::class);
    expect(fn () => $transfer->export(['stock'], $this->admin))->toThrow(DomainException::class);
    expect(fn () => $transfer->import(['groups' => [
        'catalog' => ['item_types' => [['id' => 991, 'name' => 'Nicht importieren']]],
        'inventory' => ['stock' => [['item_type_name' => 'Handschuhe', 'quantity' => 999]]],
    ]], actor: $this->admin))->toThrow(DomainException::class);
    expect(ItemType::where('name', 'Nicht importieren')->exists())->toBeFalse()
        ->and(Stock::sum('quantity'))->toBe(30);
    expect($transfer->export(['item_types'], $this->admin)['groups']['catalog']['item_types'])->toHaveCount(1);
});

it('shows a German refusal instead of a failed download for assigned stock and bound issuance exports', function (bool $bound) {
    $this->actingAs($this->admin);
    if ($bound) {
        ($this->issue)($this->south->id, $this->admin);
    }
    $component = Livewire::test(DataTransferPage::class);
    if ($bound) {
        $component->set('exportData.parts', ['issuances']);
    }
    $component->call('export')->assertSuccessful()->assertNoFileDownloaded()
        ->assertNotified(Notification::make()->title('Export nicht möglich')
            ->body('Standortbestände, mehrdeutige Bestandspositionen und Herkunftsbindungen werden von diesem Datentransfer nicht unterstützt. Wähle andere Bereiche oder verwende für eine vollständige Sicherung ein PostgreSQL-Backup.')
            ->danger());
    $component->set('exportData.parts', ['item_types'])->call('export')
        ->assertFileDownloaded('kleiderkammer-export-'.now()->format('Y-m-d').'.json');
})->with([false, true]);

it('shows a German import refusal and leaves all selected parts unchanged', function () {
    Storage::fake('local');
    $this->actingAs($this->admin);
    $json = json_encode(['version' => 1, 'groups' => [
        'catalog' => ['item_types' => [['id' => 991, 'name' => 'Nicht importieren']]],
        'inventory' => ['stock' => [['item_type_name' => 'Handschuhe', 'quantity' => 999]]],
    ]]);
    Livewire::test(DataTransferPage::class)
        ->fillForm(['file' => UploadedFile::fake()->createWithContent('export.json', $json)], 'importForm')
        ->set('importData.parts', ['item_types', 'stock'])->call('import')->assertSuccessful()
        ->assertNotified(Notification::make()->title('Import nicht möglich')
            ->body('Standortbestände, mehrdeutige Bestandspositionen und Herkunftsbindungen werden von diesem Datentransfer nicht unterstützt. Wähle andere Bereiche oder verwende für eine vollständige Sicherung ein PostgreSQL-Backup.')
            ->danger());
    expect(ItemType::where('name', 'Nicht importieren')->exists())->toBeFalse()
        ->and(Stock::sum('quantity'))->toBe(30)
        ->and(Storage::disk('local')->allFiles('kleiderkammer-imports'))->toBeEmpty();
});

it('rejects unresolved ownership and provenance metadata before any import mutation', function (string $field) {
    DB::table('kk_stock')->delete();
    expect(fn () => app(DataTransferService::class)->import(['groups' => ['inventory' => [
        'stock' => [['item_type_name' => 'Handschuhe', 'quantity' => 9, $field => 123]],
    ]]], ['stock'], actor: $this->admin))->toThrow(DomainException::class);
    expect(Stock::count())->toBe(0);
})->with(['ext_location_id', 'ext_source_stock_id']);

it('supports only unowned unique stock legacy roundtrips and refuses source-bound issuance export', function () {
    $this->south->delete();
    DB::table('kk_stock')->where('id', $this->north->id)->update(['ext_location_id' => null]);
    $transfer = app(DataTransferService::class);
    $data = $transfer->export(['stock'], $this->admin);
    $transfer->import($data, ['stock'], actor: $this->admin);
    expect(Stock::count())->toBe(1)->and(Stock::sum('quantity'))->toBe(10);
    ($this->issue)($this->north->id);
    expect(fn () => $transfer->export(['issuances'], $this->admin))->toThrow(DomainException::class);
});

it('refuses legacy import updates to a bound issuance even when the file omits provenance', function () {
    $issued = ($this->issue)($this->south->id);
    expect(fn () => app(DataTransferService::class)->import(['groups' => [
        'members' => ['members' => [['id' => 991, 'name' => $this->member->name, 'email' => $this->member->email]]],
        'inventory' => ['issued_items' => [[
            'member_id' => 991, 'item_type_name' => $this->type->name,
            'size_name' => $this->size->name, 'group_name' => $this->group->name,
            'issued_at' => $issued->issued_at->toIso8601String(), 'returned_at' => now()->toIso8601String(), 'quantity' => 999,
        ]]],
    ]], ['issuances'], actor: $this->admin))->toThrow(DomainException::class);
    expect($issued->fresh()->returned_at)->toBeNull()->and($issued->fresh()->quantity)->toBe(2)
        ->and($issued->fresh()->getAttribute('ext_source_stock_id'))->toBe($this->south->id)
        ->and(Stock::sum('quantity'))->toBe(28);
});

it('never silently merges duplicate stock rows from an ownerless legacy import', function () {
    DB::table('kk_stock')->delete();
    $row = ['item_type_name' => 'Handschuhe', 'size_name' => 'M', 'group_name' => 'Einsatz', 'quantity' => 7];
    expect(fn () => app(DataTransferService::class)->import(['groups' => [
        'inventory' => ['stock' => [$row, $row]],
    ]], ['stock'], actor: $this->admin))->toThrow(DomainException::class);
    expect(Stock::count())->toBe(0);
});

it('does not take the warehouse selection lock for wholly catalog and member transfers', function () {
    $locks = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$locks) {
        if (str_contains($query->sql, 'LOCK TABLE kk_stock')) {
            $locks[] = $query->sql;
        }
    });
    $transfer = app(DataTransferService::class);
    $data = $transfer->export(['item_types', 'members'], $this->admin);
    $transfer->import($data, ['item_types', 'members'], actor: $this->admin);
    expect($locks)->toBe([]);
});

it('requires a valid active explicit location for protected global-admin stock creation', function (?string $invalid) {
    $this->actingAs($this->admin);
    config(['locations.clothing_stock_enabled' => true]);
    $location = match ($invalid) {
        'missing' => null,
        'unknown' => 999999,
        'inactive' => Location::create(['name' => 'Alte Wache', 'slug' => 'warehouse-inactive', 'is_active' => false])->id,
        default => $this->a->id,
    };
    $type = ItemType::create(['name' => 'Mütze', 'is_tracked' => false]);
    $component = Livewire::test(CreateStock::class)->fillForm([
        'item_type_id' => $type->id, 'quantity' => 4, 'ext_location_id' => $location,
    ])->call('create');
    if ($invalid !== null) {
        $component->assertHasFormErrors(['ext_location_id']);
        expect(Stock::where('item_type_id', $type->id)->exists())->toBeFalse();
    } else {
        $component->assertHasNoFormErrors();
        $stock = Stock::where('item_type_id', $type->id)->sole();
        expect($stock->getAttribute('ext_location_id'))->toBe($this->a->id)->and($stock->quantity)->toBe(4);
    }
})->with([null, 'missing', 'unknown', 'inactive']);

it('rejects explicitly supplied inactive or invalid creation locations even with all flags disabled', function (bool $locationsEnabled, bool $inactive) {
    $this->actingAs($this->admin);
    config(['locations.enabled' => $locationsEnabled]);
    $location = $inactive
        ? Location::create(['name' => 'Alte Wache', 'slug' => 'inactive-disabled', 'is_active' => false])->id
        : 999999;
    $type = ItemType::create(['name' => 'Mütze', 'is_tracked' => false]);
    Livewire::test(CreateStock::class)->fillForm([
        'item_type_id' => $type->id, 'quantity' => 4, 'ext_location_id' => $location,
    ])->call('create')->assertHasFormErrors(['ext_location_id']);
    expect(Stock::where('item_type_id', $type->id)->exists())->toBeFalse();
})->with([false, true])->with([false, true]);

it('does not let the global admin edit form move a stock warehouse owner', function () {
    $this->actingAs($this->admin);
    config(['locations.clothing_stock_enabled' => true]);
    Livewire::test(EditStock::class, ['record' => $this->north->id])
        ->fillForm(['ext_location_id' => $this->b->id, 'quantity' => 9])
        ->call('save')->assertHasNoFormErrors();
    expect($this->north->fresh()->getAttribute('ext_location_id'))->toBe($this->a->id)
        ->and($this->north->fresh()->quantity)->toBe(9);
});

it('shows distinguishable same-catalog warehouse choices and binds the submitted stock', function () {
    $this->actingAs($this->admin);
    expect(app(StockAccess::class)->locationLabel($this->north))->toBe('Wache Nord')
        ->and(app(StockAccess::class)->locationLabel($this->south))->toBe('Wache Süd')
        ->and(app(PersonalAccess::class)->usesSourceSelector())->toBeTrue();
    Livewire::test(IssuancesRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => EditMember::class])
        ->mountAction(TestAction::make('issueStock')->table())
        ->assertFormFieldExists('stock_id', fn ($field) => str_contains($field->getOptions()[$this->north->id], 'Wache Nord')
            && str_contains($field->getOptions()[$this->south->id], 'Wache Süd'))
        ->fillForm(['stock_id' => $this->south->id, 'quantity' => 2])
        ->callMountedAction()->assertHasNoFormErrors();
    expect(IssuedItem::sole()->getAttribute('ext_source_stock_id'))->toBe($this->south->id)
        ->and($this->north->fresh()->quantity)->toBe(10)->and($this->south->fresh()->quantity)->toBe(18);
});

it('keeps stock-only quantity rights separate and rechecks grants on issue submission', function () {
    $worker = User::factory()->create(['ext_location_id' => $this->a->id]);
    $roles = app(LocationModuleRoles::class);
    $roles->grant($this->admin, $this->a->id, $worker->id, 'kleiderkammer', 'bestand', 'Bestand pflegen');
    config(['locations.clothing_stock_enabled' => true, 'locations.clothing_personal_enabled' => true]);
    app(ClothingStockService::class)->adjust($worker, $this->north->id, 11);
    expect(fn () => ($this->issue)($this->north->id, $worker))->toThrow(HttpException::class);
    $grant = $roles->grant($this->admin, $this->a->id, $worker->id, 'kleiderkammer', 'kleiderwart', 'Ausgabe');
    $this->actingAs($worker);
    $component = Livewire::test(IssuancesRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => EditMember::class])
        ->mountAction(TestAction::make('issueStock')->table())
        ->fillForm(['stock_id' => $this->north->id, 'quantity' => 2]);
    $roles->revoke($this->admin, $grant->id, $this->a->id, 'Aufgabe beendet');
    $component->callMountedAction()->assertForbidden();
    expect(IssuedItem::count())->toBe(0)->and($this->north->fresh()->quantity)->toBe(11);
});

it('rejects unauthenticated and cross-location explicit sources in protected mode', function () {
    config(['locations.clothing_stock_enabled' => true, 'locations.clothing_personal_enabled' => true]);
    expect(fn () => ($this->issue)($this->north->id))->toThrow(HttpException::class);
    $worker = User::factory()->create(['ext_location_id' => $this->a->id]);
    foreach (['bestand', 'kleiderwart'] as $role) {
        app(LocationModuleRoles::class)->grant($this->admin, $this->a->id, $worker->id, 'kleiderkammer', $role, 'Aufgabe');
    }
    expect(fn () => ($this->issue)($this->south->id, $worker))->toThrow(HttpException::class);
    expect(IssuedItem::count())->toBe(0)->and(Stock::sum('quantity'))->toBe(30);
    $issued = ($this->issue)($this->south->id, $this->admin);
    expect($issued->getAttribute('ext_source_stock_id'))->toBe($this->south->id)
        ->and($this->south->fresh()->quantity)->toBe(18);
});

it('keeps stock creation and legacy selection functional without optional Locations schema', function () {
    $this->south->delete();
    config(['locations.enabled' => false]);
    $provenance = require base_path('extensions/Locations/database/migrations/2026_12_26_000000_record_clothing_issuance_stock_source.php');
    $provenance->down();
    Schema::table('kk_stock', fn ($table) => $table->dropColumn('ext_location_id'));
    $this->migration->up();
    $this->migration->down();
    expect(app(StockAccess::class)->locationFields())->toBe([])
        ->and(app(PersonalAccess::class)->usesSourceSelector())->toBeFalse();
    $issued = ($this->issue)();
    $this->service->return($issued);
    expect($this->north->fresh()->quantity)->toBe(10);
    $other = ItemType::create(['name' => 'Gürtel', 'is_tracked' => false]);
    $this->actingAs($this->admin);
    Livewire::test(CreateStock::class)->fillForm(['item_type_id' => $other->id, 'quantity' => 5])
        ->call('create')->assertHasNoFormErrors();
    expect(Stock::where('item_type_id', $other->id)->sole()->quantity)->toBe(5);
});

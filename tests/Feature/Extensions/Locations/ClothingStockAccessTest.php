<?php

use App\Filament\ModuleAreas;
use App\Models\Module;
use App\Models\User;
use App\Services\ModuleRegistry;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\ClothingStockAccess;
use Extensions\Locations\Services\ClothingStockService;
use Extensions\Locations\Services\LocationModuleRoles;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Kleiderkammer\src\Enums\ItemStatus;
use Modules\Kleiderkammer\src\Filament\Pages\KleiderkammerDashboard;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource\Pages\EditMember;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource\RelationManagers\IssuancesRelationManager;
use Modules\Kleiderkammer\src\Filament\Resources\StockResource;
use Modules\Kleiderkammer\src\Filament\Resources\StockResource\Pages\EditStock;
use Modules\Kleiderkammer\src\Filament\Resources\StockResource\Pages\ListStock;
use Modules\Kleiderkammer\src\Filament\Widgets\OverviewWidget;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\Stock;
use Modules\Kleiderkammer\src\Services\DataTransferService;
use Modules\Kleiderkammer\src\Services\InventoryReport;
use Modules\Kleiderkammer\src\Services\IssuingService;
use Modules\Kleiderkammer\src\Services\StockAccess;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config(['locations.clothing_stock_enabled' => false]);
    $this->module = Module::where('key', 'kleiderkammer')->firstOrFail();
    $this->module->update(['is_active' => true, 'auto_assign' => false]);
    app(ModuleRegistry::class)->clearCache();
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->a = Location::create(['name' => 'Wache Nord', 'slug' => 'stock-nord']);
    $this->b = Location::create(['name' => 'Wache Süd', 'slug' => 'stock-sued']);
    $this->worker = User::factory()->create(['ext_location_id' => $this->a->id]);
    $this->recipient = User::factory()->create(['ext_location_id' => $this->a->id]);
    $this->type = ItemType::create(['name' => 'Handschuhe', 'is_tracked' => false]);
    $this->otherType = ItemType::create(['name' => 'Gürtel', 'is_tracked' => false]);
    $this->nullType = ItemType::create(['name' => 'Mütze', 'is_tracked' => false]);
    $this->stock = (new Stock(['item_type_id' => $this->type->id, 'quantity' => 12]))
        ->forceFill(['ext_location_id' => $this->a->id]);
    $this->stock->save();
    $this->other = (new Stock(['item_type_id' => $this->otherType->id, 'quantity' => 20]))
        ->forceFill(['ext_location_id' => $this->b->id]);
    $this->other->save();
    $this->null = Stock::create(['item_type_id' => $this->nullType->id, 'quantity' => 5]);
    $this->tracked = Item::create(['item_type_id' => $this->type->id, 'code' => 'STOCK-TEST-1', 'status' => ItemStatus::Available]);
    $this->access = app(ClothingStockAccess::class);
    $this->roles = app(LocationModuleRoles::class);
    $this->grant = $this->roles->grant($this->admin, $this->a->id, $this->worker->id,
        'kleiderkammer', 'bestand', 'Nur Mengenbestand', now()->addHour()->toIso8601String());
    config(['locations.clothing_stock_enabled' => true]);
    $this->legacy = function () {
        $this->worker->moduleAccess()->create(['module' => 'kleiderkammer', 'role' => 'kleiderwart']);
    };
    $this->issue = fn (?User $actor = null, ?Stock $stock = null) => app(IssuingService::class)->issueStock(
        $this->recipient, ($stock ?? $this->stock)->itemType, null, 2,
        stockId: ($stock ?? $this->stock)->id, actor: $actor ?? $this->worker);
});

it('separates grantable capabilities from certified module adapters and legacy roles', function () {
    expect(LocationModuleRoles::SUPPORTED_MODULES)->toBe(['anwesenheit', 'mitteilungen'])
        ->and(app(StockAccess::class))->toBe($this->access)
        ->and($this->worker->canManageKleiderkammer())->toBeFalse()
        ->and($this->worker->hasModuleRole('kleiderkammer', 'kleiderwart'))->toBeFalse()
        ->and($this->roles->hasAnyRole($this->worker, 'kleiderkammer', 'bestand'))->toBeFalse()
        ->and($this->access->canEnter($this->worker))->toBeTrue();
    config(['locations.scoped_role_modules' => ['kleiderkammer']]);
    expect(fn () => $this->roles->configuredModules())->toThrow(InvalidArgumentException::class);
});

it('rejects unsupported clothing capability grants', function (?string $role) {
    expect(fn () => $this->roles->grant($this->admin, $this->b->id, $this->worker->id,
        'kleiderkammer', $role, 'Test'))->toThrow(ValidationException::class);
})->with([null, 'admin']);

it('lists only exact granted targets, not null stock or session-selected locations', function () {
    $this->actingAs($this->worker);
    session(['ext_location_id' => $this->b->id]);
    expect($this->access->query($this->worker)->pluck('id')->all())->toBe([$this->stock->id])
        ->and(Stock::count())->toBe(3)
        ->and($this->access->query(null)->count())->toBe(0)
        ->and($this->access->query($this->admin)->count())->toBe(3);
});

it('allows explicit background stock adjustment but no userless console bypass', function () {
    app(ClothingStockService::class)->adjust($this->worker, $this->stock->id, 8);
    expect($this->stock->fresh()->quantity)->toBe(8);
    expect(fn () => $this->stock->update(['quantity' => 6]))->toThrow(HttpException::class);
    expect(fn () => app(ClothingStockService::class)->adjust($this->worker, $this->other->id, 6))->toThrow(HttpException::class);
    expect(fn () => app(ClothingStockService::class)->adjust($this->worker, $this->null->id, 6))->toThrow(HttpException::class);
});

it('reauthorizes held actors and targets against fresh grants and memberships', function (string $change) {
    $this->actingAs($this->worker);
    expect($this->access->canEdit($this->worker, $this->stock))->toBeTrue();
    match ($change) {
        'revoked' => $this->roles->revoke($this->admin, $this->grant->id, $this->a->id, 'Aufgabe beendet'),
        'expired' => $this->travel(2)->hours(),
        'member_inactive' => DB::table('users')->where('id', $this->worker->id)->update(['is_active' => false]),
        'membership' => DB::table('users')->where('id', $this->worker->id)->update(['ext_location_id' => $this->b->id]),
        'location_inactive' => DB::table('x_locations')->where('id', $this->a->id)->update(['is_active' => false]),
        'module_inactive' => DB::table('modules')->where('key', 'kleiderkammer')->update(['is_active' => false]),
        'target_moved' => DB::table('kk_stock')->where('id', $this->stock->id)->update(['ext_location_id' => $this->b->id]),
        'target_null' => DB::table('kk_stock')->where('id', $this->stock->id)->update(['ext_location_id' => null]),
    };
    expect($this->access->canEdit($this->worker, $this->stock))->toBeFalse();
    expect(fn () => $this->stock->update(['quantity' => 4]))->toThrow(HttpException::class);
})->with(['revoked', 'expired', 'member_inactive', 'membership', 'location_inactive', 'module_inactive', 'target_moved', 'target_null']);

it('does not let a stale global flag or a location admin authorize stock', function () {
    DB::table('users')->where('id', $this->admin->id)->update(['is_admin' => false]);
    DB::table('x_location_user')->insert(['location_id' => $this->b->id, 'user_id' => $this->worker->id, 'role' => 'admin']);
    expect($this->access->canEdit($this->admin, $this->other))->toBeFalse()
        ->and($this->access->canEdit($this->worker, $this->other))->toBeFalse();
});

it('blocks ownership catalog creation and deletion by stock-only users', function (string $mutation) {
    $this->actingAs($this->worker);
    expect(fn () => match ($mutation) {
        'create' => Stock::create(['item_type_id' => $this->type->id, 'quantity' => 1]),
        'delete' => $this->stock->delete(),
        'ownership' => $this->stock->forceFill(['ext_location_id' => $this->b->id])->save(),
        'type' => $this->stock->update(['item_type_id' => $this->otherType->id]),
        'size' => $this->stock->update(['size_id' => 99999]),
        'group' => $this->stock->update(['group_id' => 99999]),
        'id' => $this->stock->forceFill(['id' => 99999])->save(),
    })->toThrow(HttpException::class);
})->with(['create', 'delete', 'ownership', 'type', 'size', 'group', 'id']);

it('rejects negative stock adjustments including direct model writes', function () {
    $this->actingAs($this->worker);
    expect(fn () => app(ClothingStockService::class)->adjust($this->worker, $this->stock->id, -1))
        ->toThrow(ValidationException::class);
    expect(fn () => $this->stock->update(['quantity' => -1]))->toThrow(ValidationException::class);
    expect($this->stock->fresh()->quantity)->toBe(12);
});

it('grants panel entry without the legacy personal management area', function () {
    $this->actingAs($this->worker);
    expect($this->worker->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
        ->and(ListStock::allows($this->worker))->toBeTrue()
        ->and(ModuleAreas::for($this->worker))->toContain(ListStock::class)
        ->and(KleiderkammerDashboard::canAccess())->toBeFalse()
        ->and(MemberResource::canAccess())->toBeFalse()
        ->and(StockResource::canAccess())->toBeTrue()
        ->and(StockResource::canCreate())->toBeFalse()
        ->and(StockResource::canDelete($this->stock))->toBeFalse()
        ->and(StockResource::canEdit($this->other))->toBeFalse();
    Livewire::test(ListStock::class)->assertCanSeeTableRecords([$this->stock])
        ->assertCanNotSeeTableRecords([$this->other, $this->null])
        ->assertTableActionHidden('issue', $this->stock);
    Livewire::test(EditStock::class, ['record' => $this->stock->id])
        ->set('data.item_type_id', $this->otherType->id)
        ->set('data.ext_location_id', $this->b->id)
        ->fillForm(['quantity' => 7])->call('save')->assertHasNoFormErrors();
    expect($this->stock->fresh()->quantity)->toBe(7)
        ->and($this->stock->fresh()->item_type_id)->toBe($this->type->id)
        ->and($this->stock->fresh()->getAttribute('ext_location_id'))->toBe($this->a->id);
});

it('denies stock-only users every legacy clothing management entry', function (string $path) {
    $this->actingAs($this->worker)->get('/admin/kleiderkammer'.$path)->assertForbidden();
})->with(['', '/kameraden', '/einzelteile', '/kategorien', '/artikeltypen', '/bestandsbericht', '/maengelmanagement', '/datentransfer', '/scanner']);

it('reuses the existing stock index for panel entry without adding a page URL', function () {
    $this->actingAs($this->worker)->get('/admin')->assertRedirect(StockResource::getUrl('index'));
    $this->get(StockResource::getUrl('index'))->assertSuccessful();
    $this->get('/admin/standort-mengenbestand')->assertNotFound();
});

it('rejects a revoked grant at Filament save time', function () {
    $this->actingAs($this->worker);
    $page = Livewire::test(EditStock::class, ['record' => $this->stock->id])->fillForm(['quantity' => 7]);
    $this->roles->revoke($this->admin, $this->grant->id, $this->a->id, 'Beendet');
    $page->call('save')->assertNotFound();
    expect($this->stock->fresh()->quantity)->toBe(12);
});

it('keeps a legacy kleiderwart without grants out of stock when opted in', function () {
    ($this->legacy)();
    $this->roles->revoke($this->admin, $this->grant->id, $this->a->id, 'Beendet');
    $this->actingAs($this->worker);
    expect($this->worker->canManageKleiderkammer())->toBeTrue()
        ->and(StockResource::canAccess())->toBeFalse()
        ->and($this->access->query($this->worker)->count())->toBe(0);
    expect(fn () => ($this->issue)())->toThrow(HttpException::class);
});

it('does not turn stock-only permission into any personal issuing API', function (string $method) {
    expect(fn () => match ($method) {
        'stock' => ($this->issue)(),
        'tracked' => app(IssuingService::class)->issueTracked($this->recipient, $this->tracked, actor: $this->worker),
        'takeBack' => app(IssuingService::class)->takeBack($this->recipient, $this->worker),
        'retire' => app(IssuingService::class)->retire($this->tracked, actor: $this->worker),
    })->toThrow(HttpException::class);
})->with(['stock', 'tracked', 'takeBack', 'retire']);

it('requires both personal management and exact stock grant for issues and sourced returns', function () {
    ($this->legacy)();
    $issued = ($this->issue)();
    expect($issued->getAttribute('ext_source_stock_id'))->toBe($this->stock->id)
        ->and($this->stock->fresh()->quantity)->toBe(10);
    expect(fn () => ($this->issue)(stock: $this->other))->toThrow(HttpException::class);
    $this->roles->revoke($this->admin, $this->grant->id, $this->a->id, 'Beendet');
    expect(fn () => app(IssuingService::class)->return($issued, $this->worker))->toThrow(HttpException::class);
    expect($issued->fresh()->returned_at)->toBeNull()
        ->and($this->stock->fresh()->quantity)->toBe(10);
    app(IssuingService::class)->return($issued, $this->admin);
    expect($this->stock->fresh()->quantity)->toBe(12);
});

it('checks fresh personal management permission on held actors', function () {
    ($this->legacy)();
    expect($this->worker->canManageKleiderkammer())->toBeTrue();
    DB::table('module_user')->where('user_id', $this->worker->id)->delete();
    expect(fn () => ($this->issue)())->toThrow(HttpException::class);
});

it('does not allow stock-only users to return another persons issuance', function () {
    $issued = ($this->issue)($this->admin);
    expect(fn () => app(IssuingService::class)->return($issued, $this->worker))->toThrow(HttpException::class);
    expect($issued->fresh()->returned_at)->toBeNull()
        ->and($this->stock->fresh()->quantity)->toBe(10);
});

it('does not let the stock identifier override a different catalog triplet', function () {
    ($this->legacy)();
    expect(fn () => app(IssuingService::class)->issueStock(
        $this->recipient, $this->type, null, 1, stockId: $this->other->id, actor: $this->worker,
    ))->toThrow(HttpException::class);
    expect(IssuedItem::count())->toBe(0)
        ->and($this->other->fresh()->quantity)->toBe(20);
});

it('allows globals to adjust unassigned stock and retains catalog management', function () {
    $this->actingAs($this->admin);
    $this->null->update(['quantity' => 6]);
    expect($this->null->fresh()->quantity)->toBe(6)
        ->and(StockResource::canCreate())->toBeTrue()
        ->and(StockResource::canDelete($this->null))->toBeTrue();
    $this->stock->forceFill(['ext_location_id' => null])->save();
    expect($this->stock->fresh()->getAttribute('ext_location_id'))->toBeNull();
});

it('requires explicit actors for background issuance and restores the observer actor after failures', function () {
    expect(fn () => app(IssuingService::class)->issueStock($this->recipient, $this->type, null, 2))
        ->toThrow(HttpException::class);
    ($this->issue)($this->admin);
    expect($this->stock->fresh()->quantity)->toBe(10);
    expect(fn () => $this->stock->update(['quantity' => 1]))->toThrow(HttpException::class);
});

it('keeps historical null source returns global only and never guesses a source', function () {
    $issued = IssuedItem::create([
        'user_id' => $this->recipient->id, 'item_type_id' => $this->type->id,
        'quantity' => 2, 'issued_at' => now(),
    ]);
    ($this->legacy)();
    expect(fn () => app(IssuingService::class)->return($issued, $this->worker))->toThrow(HttpException::class);
    expect($issued->fresh()->returned_at)->toBeNull();
    app(IssuingService::class)->return($issued, $this->admin);
    expect($issued->fresh()->getAttribute('ext_source_stock_id'))->toBeNull()
        ->and($this->stock->fresh()->quantity)->toBe(14);
});

it('rolls back all takeBack returns if one source is unauthorized', function () {
    ($this->legacy)();
    $first = ($this->issue)($this->admin);
    $second = ($this->issue)($this->admin, $this->other);
    expect(fn () => app(IssuingService::class)->takeBack($this->recipient, $this->worker))->toThrow(HttpException::class);
    expect($first->fresh()->returned_at)->toBeNull()
        ->and($second->fresh()->returned_at)->toBeNull()
        ->and($this->stock->fresh()->quantity)->toBe(10)
        ->and($this->other->fresh()->quantity)->toBe(18);
});

it('does not authorize ambiguous historical rows just because their catalog snapshot was cleared', function () {
    ($this->legacy)();
    $issued = IssuedItem::create([
        'user_id' => $this->recipient->id, 'quantity' => 2, 'issued_at' => now(),
    ]);
    expect(fn () => app(IssuingService::class)->return($issued, $this->worker))->toThrow(HttpException::class);
    expect($issued->fresh()->returned_at)->toBeNull();
    app(IssuingService::class)->return($issued, $this->admin);
    expect($issued->fresh()->returned_at)->not->toBeNull();
});

it('makes bulk transfer global only, including direct service invocations', function () {
    ($this->legacy)();
    $transfer = app(DataTransferService::class);
    expect(fn () => $transfer->export(['stock'], $this->worker))->toThrow(HttpException::class);
    expect(fn () => $transfer->import(['groups' => []], actor: $this->worker))->toThrow(HttpException::class);
    expect(fn () => $transfer->export(['stock']))->toThrow(HttpException::class);
    expect(fn () => $transfer->export(['stock'], $this->admin))->toThrow(DomainException::class);
});

it('does not expose personal reporting APIs or dashboard counters through a stock-only grant', function (string $method) {
    $this->actingAs($this->worker);
    expect(OverviewWidget::canView())->toBeFalse();
    expect(fn () => app(InventoryReport::class)->{$method}($this->worker))->toThrow(HttpException::class);
})->with(['stockByType', 'trackedByType', 'openLoans', 'summary']);

it('scopes report stock relations independently of legacy personal authority', function () {
    ($this->legacy)();
    $this->actingAs($this->worker);
    $report = app(InventoryReport::class);
    expect(OverviewWidget::canView())->toBeTrue()
        ->and($report->stockByType()->pluck('item_type_id')->all())->toBe([$this->type->id])
        ->and($report->stockByType()->sum('quantity'))->toBe(12)
        ->and($report->stockByType($this->admin)->sum('quantity'))->toBe(37);
    $this->roles->revoke($this->admin, $this->grant->id, $this->a->id, 'Beendet');
    expect($report->stockByType())->toBeEmpty()
        ->and($report->openLoans())->toBeEmpty()
        ->and($report->summary()['open_loans'])->toBe(0);
    $this->get('/admin/kleiderkammer/bestandsbericht')->assertSuccessful();
});

it('offers only authorized stock identifiers and counts in the personal issuance selector', function () {
    ($this->legacy)();
    $this->actingAs($this->worker);
    Livewire::test(IssuancesRelationManager::class, ['ownerRecord' => $this->recipient, 'pageClass' => EditMember::class])
        ->mountAction(TestAction::make('issueStock')->table())
        ->assertFormFieldExists('stock_id', fn ($field) => array_keys($field->getOptions()) === [$this->stock->id])
        ->fillForm(['stock_id' => $this->stock->id, 'quantity' => 1])
        ->callMountedAction()
        ->assertNotified('Aus Bestand ausgegeben.');
    expect($this->stock->fresh()->quantity)->toBe(11)
        ->and($this->other->fresh()->quantity)->toBe(20)
        ->and($this->null->fresh()->quantity)->toBe(5);
});

it('requires explicit report actors outside authenticated requests when enabled', function (string $method) {
    expect(fn () => app(InventoryReport::class)->{$method}())->toThrow(HttpException::class);
})->with(['stockByType', 'trackedByType', 'openLoans', 'summary']);

it('preserves default-off behavior without activating prepared capability grants', function () {
    config(['locations.clothing_stock_enabled' => false]);
    expect($this->access->canEnter($this->worker))->toBeFalse()
        ->and(ListStock::allows($this->worker))->toBeFalse();
    ($this->legacy)();
    expect($this->access->canEnter($this->worker))->toBeTrue()
        ->and($this->access->query($this->worker)->count())->toBe(3);
    $issued = app(IssuingService::class)->issueStock($this->recipient, $this->type, null, 2);
    app(IssuingService::class)->return($issued);
    $this->stock->update(['quantity' => 9]);
    expect($this->stock->fresh()->quantity)->toBe(9);
});

it('fails closed at startup validation for disabled Locations or missing provenance schema', function (string $missing) {
    if ($missing === 'locations') {
        config(['locations.enabled' => false]);
    } else {
        Schema::table('kk_issued_items', fn ($table) => $table->dropColumn('ext_source_stock_id'));
    }
    expect(fn () => $this->access->validateConfiguration())->toThrow(InvalidArgumentException::class);
})->with(['locations', 'source']);

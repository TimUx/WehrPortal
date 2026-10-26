<?php

use App\Models\Module;
use App\Models\User;
use App\Services\InventoryRegistry;
use App\Services\ModuleRegistry;
use App\Services\ScanRegistry;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\ClothingPersonalAccess;
use Extensions\Locations\Services\LocationModuleRoles;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Modules\Kleiderkammer\src\Enums\ItemStatus;
use Modules\Kleiderkammer\src\Filament\Pages\KleiderkammerDashboard;
use Modules\Kleiderkammer\src\Filament\Resources\EquipmentMessageResource\Pages\ListEquipmentMessages;
use Modules\Kleiderkammer\src\Filament\Resources\ItemResource;
use Modules\Kleiderkammer\src\Filament\Resources\ItemResource\Pages\ListItems;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource\Pages\EditMember;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource\Pages\ListMembers;
use Modules\Kleiderkammer\src\Filament\Resources\StockResource;
use Modules\Kleiderkammer\src\Livewire\EquipmentMessageForm;
use Modules\Kleiderkammer\src\Models\EquipmentMessage;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemTreatment;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\MemberSize;
use Modules\Kleiderkammer\src\Models\Size;
use Modules\Kleiderkammer\src\Models\Stock;
use Modules\Kleiderkammer\src\Notifications\EquipmentMessageNotification;
use Modules\Kleiderkammer\src\Notifications\WashingDoneNotification;
use Modules\Kleiderkammer\src\Notifications\WashingRequestedNotification;
use Modules\Kleiderkammer\src\Services\CodeResolver;
use Modules\Kleiderkammer\src\Services\DataTransferService;
use Modules\Kleiderkammer\src\Services\EquipmentMessageService;
use Modules\Kleiderkammer\src\Services\InventoryReport;
use Modules\Kleiderkammer\src\Services\IssuingService;
use Modules\Kleiderkammer\src\Services\LabelSheetGenerator;
use Modules\Kleiderkammer\src\Services\PersonalAccess;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config(['locations.clothing_personal_enabled' => false, 'locations.clothing_stock_enabled' => false]);
    Module::where('key', 'kleiderkammer')->update(['is_active' => true, 'auto_assign' => true]);
    app(ModuleRegistry::class)->clearCache();
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->a = Location::create(['name' => 'Wache Nord', 'slug' => 'personal-nord']);
    $this->b = Location::create(['name' => 'Wache Süd', 'slug' => 'personal-sued']);
    $this->worker = User::factory()->create(['ext_location_id' => $this->a->id]);
    $this->stockOnly = User::factory()->create(['ext_location_id' => $this->a->id]);
    $this->combined = User::factory()->create(['ext_location_id' => $this->a->id]);
    $this->legacy = User::factory()->create(['ext_location_id' => $this->a->id]);
    $this->legacy->moduleAccess()->create(['module' => 'kleiderkammer', 'role' => 'kleiderwart']);
    $this->south = User::factory()->create(['ext_location_id' => $this->b->id]);
    $this->shared = User::factory()->create(['ext_location_id' => $this->a->id, 'notes' => 'Gemeinsames Profil']);
    DB::table('x_location_user')->insert(['location_id' => $this->b->id, 'user_id' => $this->shared->id, 'role' => 'member']);
    $this->foreign = User::factory()->create(['ext_location_id' => $this->b->id, 'notes' => 'Süd-Profil']);
    $this->unknown = User::factory()->create();
    DB::table('users')->where('id', $this->unknown->id)->update(['ext_location_id' => null]);
    $this->type = ItemType::create(['name' => 'Jacke', 'is_tracked' => true, 'has_size' => true]);
    $this->bulkType = ItemType::create(['name' => 'Handschuhe', 'is_tracked' => false]);
    $this->size = Size::create(['item_type_id' => $this->type->id, 'name' => 'L']);
    $this->profile = MemberSize::create(['user_id' => $this->shared->id, 'item_type_id' => $this->type->id, 'size_id' => $this->size->id]);
    $makeItem = function ($location, $code, $status = ItemStatus::Issued) {
        $item = (new Item(['item_type_id' => $this->type->id, 'code' => $code, 'status' => $status]))
            ->forceFill(['ext_location_id' => $location]);
        $item->save();

        return $item;
    };
    $this->itemA = $makeItem($this->a->id, 'PERSONAL-A');
    $this->itemB = $makeItem($this->b->id, 'PERSONAL-B');
    $this->spareA = $makeItem($this->a->id, 'SPARE-A', ItemStatus::Available);
    $this->spareB = $makeItem($this->b->id, 'SPARE-B', ItemStatus::Available);
    $this->nullItem = $makeItem(null, 'SPARE-NULL', ItemStatus::Available);
    DB::table('kk_items')->where('id', $this->nullItem->id)->update(['ext_location_id' => null]);
    $makeStock = function ($location, $typeId) {
        $stock = (new Stock(['item_type_id' => $typeId, 'quantity' => 20]))->forceFill(['ext_location_id' => $location]);
        $stock->save();

        return $stock;
    };
    $this->stockA = $makeStock($this->a->id, $this->bulkType->id);
    $this->stockB = $makeStock($this->b->id, ItemType::create(['name' => 'Mütze', 'is_tracked' => false])->id);
    $makeIssued = function ($itemId = null, $stockId = null) {
        $issued = (new IssuedItem(['user_id' => $this->shared->id, 'item_id' => $itemId,
            'item_type_id' => $itemId === null ? ($stockId === null ? $this->bulkType->id : Stock::findOrFail($stockId)->item_type_id) : null, 'quantity' => 1, 'issued_at' => now()]))
            ->forceFill(['ext_source_stock_id' => $stockId]);
        $issued->save();

        return $issued;
    };
    $this->issuedA = $makeIssued($this->itemA->id);
    $this->issuedB = $makeIssued($this->itemB->id);
    $this->bulkA = $makeIssued(null, $this->stockA->id);
    $this->bulkB = $makeIssued(null, $this->stockB->id);
    $this->unknownIssued = $makeIssued();
    $this->conflict = $makeIssued($this->nullItem->id);
    $this->messageA = EquipmentMessage::create(['user_id' => $this->shared->id, 'issued_item_id' => $this->issuedA->id, 'item_label' => 'Nord-Jacke', 'body' => 'Nord-Nachricht']);
    $this->messageB = EquipmentMessage::create(['user_id' => $this->shared->id, 'issued_item_id' => $this->issuedB->id, 'item_label' => 'Süd-Jacke', 'body' => 'Süd-Nachricht']);
    $this->roles = app(LocationModuleRoles::class);
    $grant = fn ($user, $location, $role) => $this->roles->grant($this->admin, $location->id, $user->id, 'kleiderkammer', $role, 'Aufgabe übernehmen', now()->addHour()->toIso8601String());
    $this->grant = $grant($this->worker, $this->a, 'kleiderwart');
    $grant($this->stockOnly, $this->a, 'bestand');
    $grant($this->combined, $this->a, 'bestand');
    $grant($this->combined, $this->a, 'kleiderwart');
    $grant($this->south, $this->b, 'kleiderwart');
    $this->access = app(PersonalAccess::class);
    config(['locations.clothing_personal_enabled' => true]);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('keeps capabilities distinct from certification and default legacy role APIs', function () {
    expect($this->access)->toBeInstanceOf(ClothingPersonalAccess::class)
        ->and($this->worker->canManageKleiderkammer())->toBeTrue()
        ->and($this->stockOnly->canManageKleiderkammer())->toBeFalse()
        ->and($this->legacy->canManageKleiderkammer())->toBeFalse()
        ->and($this->worker->hasModuleRole('kleiderkammer', 'kleiderwart'))->toBeFalse()
        ->and(LocationModuleRoles::SUPPORTED_MODULES)->toBe(['anwesenheit', 'mitteilungen']);
    expect($this->access->members($this->worker)->pluck('id')->all())->toContain($this->shared->id)->not->toContain($this->foreign->id, $this->unknown->id);
    config(['locations.scoped_role_modules' => ['kleiderkammer']]);
    expect(fn () => $this->roles->configuredModules())->toThrow(InvalidArgumentException::class);
});

it('limits the shared personal profile but independently filters every history source', function () {
    $this->actingAs($this->worker);
    expect(MemberSize::pluck('id')->all())->toBe([$this->profile->id])
        ->and(IssuedItem::pluck('id')->all())->toBe([$this->issuedA->id, $this->bulkA->id])
        ->and(EquipmentMessage::pluck('id')->all())->toBe([$this->messageA->id])
        ->and($this->shared->kleiderkammerItems()->count())->toBe(2)
        ->and(app(InventoryReport::class)->openLoans()->pluck('id')->all())->toBe([$this->issuedA->id, $this->bulkA->id]);
    $this->actingAs($this->south);
    expect(MemberSize::pluck('id')->all())->toBe([$this->profile->id])
        ->and(IssuedItem::pluck('id')->all())->toBe([$this->issuedB->id, $this->bulkB->id]);
});

it('allows self and global admin unknown and conflicting history without granting management', function (string $who) {
    $this->actingAs($this->{$who});
    expect(IssuedItem::count())->toBe(6)->and(EquipmentMessage::count())->toBe(2);
    if ($who === 'shared') {
        expect(fn () => app(IssuingService::class)->return($this->unknownIssued))->toThrow(HttpException::class);
    }
})->with(['shared', 'admin']);

it('denies actorless query and direct service backgrounds, but accepts explicit actors', function () {
    expect(IssuedItem::count())->toBe(0)->and(MemberSize::count())->toBe(0)->and(Item::count())->toBe(0);
    expect(fn () => app(IssuingService::class)->issueTracked($this->shared, $this->spareA))->toThrow(HttpException::class);
    $issued = app(IssuingService::class)->issueTracked($this->shared, $this->spareA, actor: $this->worker);
    expect($issued->item_id)->toBe($this->spareA->id);
    app(IssuingService::class)->return($issued, $this->worker);
    expect(DB::table('kk_issued_items')->where('id', $issued->id)->value('returned_at'))->not->toBeNull();
});

it('requires person and physical source authority for tracked issuance', function (string $target) {
    $member = $target === 'foreign-member' ? $this->foreign : $this->shared;
    $item = match ($target) {
        'foreign-source' => $this->spareB,
        'unknown-source' => $this->nullItem,
        default => $this->spareA,
    };
    expect(fn () => app(IssuingService::class)->issueTracked($member, $item, actor: $this->worker))->toThrow(HttpException::class);
})->with(['foreign-member', 'foreign-source', 'unknown-source']);

it('requires both capabilities at the source when the stock adapter is also on', function (string $who) {
    config(['locations.clothing_stock_enabled' => true]);
    $issue = fn () => app(IssuingService::class)->issueTracked($this->shared, $this->spareA, actor: $this->{$who});
    if (in_array($who, ['combined', 'admin'])) {
        expect($issue()->item_id)->toBe($this->spareA->id);
    } else {
        expect($issue)->toThrow(HttpException::class);
    }
})->with(['worker', 'stockOnly', 'legacy', 'combined', 'admin']);

it('uses an explicit bulk source with personal on and stock off, without permitting stock edits', function () {
    $issued = app(IssuingService::class)->issueStock($this->shared, $this->bulkType, null, 2, stockId: $this->stockA->id, actor: $this->worker);
    expect($issued->getAttribute('ext_source_stock_id'))->toBe($this->stockA->id);
    app(IssuingService::class)->return($issued, $this->worker);
    expect($this->stockA->fresh()->quantity)->toBe(20);
    $this->actingAs($this->worker);
    expect(StockResource::canAccess())->toBeFalse();
    expect(fn () => $this->stockA->update(['quantity' => 3]))->toThrow(HttpException::class);
    expect(fn () => app(IssuingService::class)->issueStock($this->shared, $this->bulkType, null, 1, actor: $this->worker))->toThrow(HttpException::class);
    expect(fn () => app(IssuingService::class)->issueStock($this->shared, $this->bulkType, null, 1, stockId: $this->stockB->id, actor: $this->worker))->toThrow(HttpException::class);
});

it('rejects mixed-source takeback atomically rather than returning a scoped subset', function () {
    expect(fn () => app(IssuingService::class)->takeBack($this->shared, $this->worker))->toThrow(HttpException::class);
    expect(DB::table('kk_issued_items')->whereNotNull('returned_at')->count())->toBe(0)
        ->and($this->stockA->fresh()->quantity)->toBe(20)
        ->and(DB::table('kk_items')->where('id', $this->itemA->id)->value('status'))->toBe('issued');
});

it('rejects every unknown conflicting or foreign return independently', function (string $record) {
    expect(fn () => app(IssuingService::class)->return($this->{$record}, $this->worker))->toThrow(HttpException::class);
})->with(['issuedB', 'bulkB', 'unknownIssued', 'conflict']);

it('reauthorizes stale actors grants memberships and source rows', function (string $change) {
    $this->actingAs($this->worker);
    expect($this->access->canEnter($this->worker))->toBeTrue();
    match ($change) {
        'revoke' => $this->roles->revoke($this->admin, $this->grant->id, $this->a->id, 'Beendet'),
        'expiry' => $this->travel(2)->hours(),
        'actor' => DB::table('users')->where('id', $this->worker->id)->update(['is_active' => false]),
        'membership' => (function () {
            DB::table('users')->where('id', $this->worker->id)->update(['ext_location_id' => $this->b->id]);
            $this->worker->locations()->detach($this->a->id);
        })(),
        'member' => (function () {
            DB::table('users')->where('id', $this->shared->id)->update(['ext_location_id' => null]);
            $this->shared->locations()->detach();
        })(),
        'location' => DB::table('x_locations')->where('id', $this->a->id)->update(['is_active' => false]),
        'module' => DB::table('modules')->where('key', 'kleiderkammer')->update(['is_active' => false]),
        'source' => DB::table('kk_items')->where('id', $this->itemA->id)->update(['ext_location_id' => $this->b->id]),
        'null-source' => DB::table('kk_items')->where('id', $this->itemA->id)->update(['ext_location_id' => null]),
        'bulk-source' => DB::table('kk_stock')->where('id', $this->stockA->id)->update(['ext_location_id' => $this->b->id]),
    };
    $target = $change === 'bulk-source' ? $this->bulkA : $this->issuedA;
    expect(fn () => app(IssuingService::class)->return($target, $this->worker))->toThrow(HttpException::class);
})->with(['revoke', 'expiry', 'actor', 'membership', 'member', 'location', 'module', 'source', 'null-source', 'bulk-source']);

it('does not trust stale admin flags', function () {
    DB::table('users')->where('id', $this->admin->id)->update(['is_admin' => false]);
    expect($this->admin->is_admin)->toBeTrue()->and($this->admin->canManageKleiderkammer())->toBeFalse();
    expect(fn () => app(IssuingService::class)->issueTracked($this->foreign, $this->spareB, actor: $this->admin))->toThrow(HttpException::class);
});

it('authorizes Filament list ids notes and relation data at call time', function () {
    $this->actingAs($this->worker);
    expect(KleiderkammerDashboard::canAccess())->toBeTrue()
        ->and(MemberResource::canEdit($this->foreign))->toBeFalse();
    Livewire::test(ListMembers::class)->assertCanSeeTableRecords([$this->shared])->assertCanNotSeeTableRecords([$this->foreign, $this->unknown]);
    Livewire::test(EditMember::class, ['record' => $this->foreign->id])->assertNotFound();
    $page = Livewire::test(EditMember::class, ['record' => $this->shared->id])->set('data.notes', 'Geändert');
    $this->roles->revoke($this->admin, $this->grant->id, $this->a->id, 'Beendet');
    $page->call('save')->assertNotFound();
    expect(DB::table('users')->where('id', $this->shared->id)->value('notes'))->toBe('Gemeinsames Profil');
});

it('authorizes direct member size edits against real membership and fresh grants', function () {
    $this->actingAs($this->worker);
    $this->profile->update(['size_id' => $this->size->id]);
    DB::table('users')->where('id', $this->shared->id)->update(['ext_location_id' => null]);
    $this->shared->locations()->detach();
    expect(fn () => $this->profile->update(['group_id' => 999]))->toThrow(HttpException::class);
});

it('filters scanner search catalogs reports and transfer boundaries', function () {
    $this->actingAs($this->worker);
    expect(app(ScanRegistry::class)->resolveKnown('PERSONAL-A'))->not->toBeNull()
        ->and(app(ScanRegistry::class)->resolveKnown('PERSONAL-B'))->toBeNull()
        ->and(app(ScanRegistry::class)->resolve('UNKNOWN-PERSONAL'))->toBeNull()
        ->and(app(InventoryReport::class)->summary()['open_loans'])->toBe(2)
        ->and(app(InventoryReport::class)->stockByType()->sum('quantity'))->toBe(20);
    expect(app(InventoryRegistry::class)->search('PERSONAL', $this->worker)->get('kleiderkammer')['rows']->pluck('code')->all())->toBe(['PERSONAL-A']);
    expect(fn () => app(DataTransferService::class)->export(actor: $this->worker))->toThrow(HttpException::class);
    expect(fn () => app(DataTransferService::class)->import([], actor: $this->worker))->toThrow(HttpException::class);
    expect(fn () => app(LabelSheetGenerator::class)->generate(collect([$this->itemB])))->toThrow(HttpException::class);
    expect(fn () => $this->type->update(['name' => 'Geändert']))->toThrow(HttpException::class);
});

it('keeps self-service Livewire and direct messaging strictly on the authenticated person', function () {
    Notification::fake();
    $this->actingAs($this->shared);
    Livewire::test(EquipmentMessageForm::class, ['issuedItemId' => $this->issuedB->id])
        ->set('body', 'Bitte prüfen')->call('send')->assertHasNoErrors();
    $this->actingAs($this->foreign);
    Livewire::test(EquipmentMessageForm::class, ['issuedItemId' => $this->issuedA->id])
        ->set('body', 'Bitte prüfen')->call('send')->assertNotFound();
    expect(fn () => app(EquipmentMessageService::class)->send($this->shared, $this->issuedA, 'Nicht meine Nachricht'))->toThrow(HttpException::class);
});

it('notifies only fresh personal source managers and globals, slicing multi-location washing', function () {
    Notification::fake();
    $service = app(EquipmentMessageService::class);
    $service->send($this->shared, $this->issuedA, 'Nord-Mangel', actor: $this->shared);
    Notification::assertSentTo([$this->worker, $this->combined, $this->admin], EquipmentMessageNotification::class);
    Notification::assertNotSentTo([$this->stockOnly, $this->south, $this->legacy], EquipmentMessageNotification::class);
    $service->requestWashing($this->shared, [$this->issuedA->id, $this->issuedB->id], 'Nach Einsatz', actor: $this->shared);
    Notification::assertSentTo($this->worker, WashingRequestedNotification::class, fn ($notification) => ! str_contains($notification->toMail($this->worker)->render(), 'PERSONAL-B'));
    Notification::assertSentTo($this->south, WashingRequestedNotification::class);
    Notification::assertNotSentTo([$this->stockOnly, $this->legacy], WashingRequestedNotification::class);
});

it('preserves default-off direct legacy behavior and source-aware installed returns', function () {
    config(['locations.clothing_personal_enabled' => false]);
    expect($this->legacy->canManageKleiderkammer())->toBeTrue();
    app(IssuingService::class)->return($this->bulkB);
    expect($this->stockB->fresh()->quantity)->toBe(21)->and($this->stockA->fresh()->quantity)->toBe(20);
});

it('validates prerequisites and never promotes clothing into full module certification', function () {
    $this->access->validateConfiguration();
    config(['locations.enabled' => false]);
    expect(fn () => $this->access->validateConfiguration())->toThrow(InvalidArgumentException::class);
});

it('fails configuration validation closed on missing source schema', function () {
    Schema::shouldReceive('hasColumns')->andReturn(false);
    expect(fn () => $this->access->validateConfiguration())->toThrow(InvalidArgumentException::class);
});

it('blocks legacy and stock-only manager entry and APIs while personal protection is on', function (string $who) {
    config(['locations.clothing_stock_enabled' => true]);
    $this->actingAs($this->{$who});
    $this->get('/admin/kleiderkammer/kameraden')->assertForbidden();
    expect(IssuedItem::count())->toBe(0)
        ->and(MemberSize::count())->toBe(0)
        ->and(EquipmentMessage::count())->toBe(0);
    expect(fn () => app(InventoryReport::class)->openLoans())->toThrow(HttpException::class);
    expect(fn () => app(IssuingService::class)->takeBack($this->shared))->toThrow(HttpException::class);
})->with(['legacy', 'stockOnly']);

it('checks retire and message handling services against fresh source authority', function () {
    expect(fn () => app(IssuingService::class)->retire($this->spareB, actor: $this->worker))->toThrow(HttpException::class);
    app(IssuingService::class)->retire($this->spareA, actor: $this->worker);
    expect(DB::table('kk_items')->where('id', $this->spareA->id)->value('status'))->toBe('retired');
    $this->actingAs($this->worker);
    expect(fn () => $this->messageB->update(['handled_at' => now(), 'handled_by' => $this->worker->id]))->toThrow(HttpException::class);
    $this->messageA->update(['handled_at' => now(), 'handled_by' => $this->worker->id]);
    expect(DB::table('kk_equipment_messages')->where('id', $this->messageA->id)->value('handled_at'))->not->toBeNull();
});

it('checks delayed notification rendering against revoked grants and complete source lists', function () {
    $notice = new EquipmentMessageNotification($this->messageA);
    $this->roles->revoke($this->admin, $this->grant->id, $this->a->id, 'Beendet');
    expect(fn () => $notice->toMail($this->worker))->toThrow(HttpException::class);
    $washing = new WashingRequestedNotification($this->shared, collect([$this->messageA, $this->messageB]));
    expect(fn () => $washing->toMail($this->combined))->toThrow(HttpException::class);
});

it('preserves database rejection of conflicting and rewritten historical sources', function () {
    expect(fn () => DB::transaction(fn () => DB::table('kk_issued_items')->where('id', $this->bulkA->id)->update(['ext_source_stock_id' => $this->stockB->id])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('kk_issued_items')->insert([
        'user_id' => $this->shared->id, 'item_id' => $this->itemA->id, 'ext_source_stock_id' => $this->stockA->id,
        'quantity' => 1, 'issued_at' => now(),
    ])))->toThrow(QueryException::class);
});

it('keeps explicit admin member exports complete but refuses unrepresentable stock provenance', function () {
    $this->actingAs($this->worker);
    $data = app(DataTransferService::class)->export(['members'], actor: $this->admin);
    expect(fn () => app(DataTransferService::class)->export(['issuances'], actor: $this->admin))->toThrow(DomainException::class);
    expect(collect($data['groups']['members']['members'])->pluck('email')->all())->toContain($this->foreign->email);
    expect(app(InventoryReport::class)->openLoans($this->admin)->count())->toBe(6);
});

it('does not invent local provenance for newly imported tracked history', function () {
    $this->actingAs($this->admin);
    session(['ext_location_id' => $this->a->id]);
    app(DataTransferService::class)->import(['groups' => ['inventory' => ['items' => [
        ['code' => 'IMPORTED-UNKNOWN', 'item_type_name' => 'Jacke', 'status' => 'available'],
    ]]]], ['items'], actor: $this->admin);
    expect(DB::table('kk_items')->where('code', 'IMPORTED-UNKNOWN')->value('ext_location_id'))->toBeNull();
    $this->actingAs($this->worker);
    expect(Item::where('code', 'IMPORTED-UNKNOWN')->exists())->toBeFalse();
});

it('does not assign imported members to the importing actors current location', function () {
    $this->actingAs($this->admin);
    session(['ext_location_id' => $this->a->id]);
    app(DataTransferService::class)->import(['groups' => ['members' => ['members' => [
        ['id' => 991, 'name' => 'Hanna Hydrant', 'email' => 'import.hanna@example.com'],
    ]]]], ['members'], actor: $this->admin);
    expect(DB::table('users')->where('email', 'import.hanna@example.com')->value('ext_location_id'))->toBeNull();
    expect($this->access->members($this->worker)->where('email', 'import.hanna@example.com')->exists())->toBeFalse();
});

it('sends unknown-source self messages only to global admins without a local fallback', function () {
    Notification::fake();
    app(EquipmentMessageService::class)->send($this->shared, $this->unknownIssued, 'Unbekannte Herkunft', actor: $this->shared);
    Notification::assertSentTo($this->admin, EquipmentMessageNotification::class);
    Notification::assertNotSentTo([$this->worker, $this->combined, $this->south, $this->stockOnly, $this->legacy], EquipmentMessageNotification::class);
});

it('projects only the minimal shared person profile without general member notes', function () {
    DB::table('users')->where('id', $this->shared->id)->update(['rank' => 'Vertraulicher Dienstgrad', 'street' => 'Vertrauliche Straße']);
    $this->actingAs($this->worker);
    $list = Livewire::test(ListMembers::class);
    $list->assertTableColumnDoesNotExist('rank')->assertTableColumnDoesNotExist('groups.name')->assertTableColumnDoesNotExist('notes');
    $page = Livewire::test(EditMember::class, ['record' => $this->shared->id]);
    expect(array_intersect(array_keys($page->get('data')), ['rank', 'street', 'email', 'phone', 'birth_date', 'ext_location_id', 'notes']))->toBe([]);
    $page->assertDontSee('Vertraulicher Dienstgrad')->assertDontSee('Gemeinsames Profil')
        ->set('data.rank', 'Manipuliert')->set('data.email', 'changed@example.com')
        ->set('data.notes', 'Gemeinsame allgemeine Notiz')->call('save')->assertHasNoFormErrors();
    $fresh = DB::table('users')->where('id', $this->shared->id)->first();
    expect($fresh->notes)->toBe('Gemeinsames Profil')->and($fresh->rank)->toBe('Vertraulicher Dienstgrad')
        ->and($fresh->email)->toBe($this->shared->email);
});

it('requires recipient membership at the exact source even with grants for both locations', function (string $source) {
    DB::table('x_location_user')->insert(['location_id' => $this->b->id, 'user_id' => $this->worker->id, 'role' => 'member']);
    $this->roles->grant($this->admin, $this->b->id, $this->worker->id, 'kleiderkammer', 'kleiderwart', 'Zusätzliche Aufgabe');
    expect($this->access->locationIds($this->worker))->toContain($this->a->id, $this->b->id);
    $issue = fn () => $source === 'tracked'
        ? app(IssuingService::class)->issueTracked($this->foreign, $this->spareA, actor: $this->worker)
        : app(IssuingService::class)->issueStock($this->foreign, $this->bulkType, null, 1, stockId: $this->stockA->id, actor: $this->worker);
    expect($issue)->toThrow(HttpException::class);
    expect(DB::table('kk_stock')->where('id', $this->stockA->id)->value('quantity'))->toBe(20);
    expect(DB::table('kk_items')->where('id', $this->spareA->id)->value('status'))->toBe('available');
})->with(['tracked', 'bulk']);

it('correlates history and notifications to the exact recipient source membership', function (string $record) {
    Notification::fake();
    DB::table('x_location_user')->insert(['location_id' => $this->b->id, 'user_id' => $this->worker->id, 'role' => 'member']);
    $this->roles->grant($this->admin, $this->b->id, $this->worker->id, 'kleiderkammer', 'kleiderwart', 'Zusätzliche Aufgabe');
    DB::table('users')->where('id', $this->shared->id)->update(['ext_location_id' => $this->b->id]);
    $this->shared->locations()->detach($this->a->id);
    $issued = $this->{$record};
    $this->actingAs($this->worker);
    expect(IssuedItem::whereKey($issued->id)->exists())->toBeFalse()
        ->and($this->access->canManageIssuance($this->worker, $issued))->toBeFalse()
        ->and(EquipmentMessage::where('issued_item_id', $issued->id)->exists())->toBeFalse();
    expect(fn () => app(IssuingService::class)->return($issued, $this->worker))->toThrow(HttpException::class);
    app(EquipmentMessageService::class)->send($this->shared, $issued, 'Herkunft prüfen', actor: $this->shared);
    Notification::assertSentTo($this->admin, EquipmentMessageNotification::class);
    Notification::assertNotSentTo($this->worker, EquipmentMessageNotification::class);
})->with(['issuedA', 'bulkA']);

it('allows exact source membership through the pivot and an unrestricted active global admin', function () {
    $issued = app(IssuingService::class)->issueTracked($this->shared, $this->spareB, actor: $this->south);
    expect($issued->user_id)->toBe($this->shared->id);
    $adminIssued = app(IssuingService::class)->issueTracked($this->foreign, $this->spareA, actor: $this->admin);
    expect($adminIssued->user_id)->toBe($this->foreign->id);
});

it('keeps treatment histories and all direct treatment mutations global only', function (string $operation) {
    $treatment = $this->access->asActor($this->admin, fn () => ItemTreatment::create([
        'item_id' => $this->itemA->id, 'type' => 'washing', 'treated_at' => now(), 'notes' => 'Unklare Personenzuordnung',
    ]));
    $this->actingAs($this->worker);
    expect(ItemTreatment::count())->toBe(0)->and($this->itemA->treatments()->count())->toBe(0);
    $mutate = fn () => match ($operation) {
        'create' => ItemTreatment::create(['item_id' => $this->itemA->id, 'type' => 'washing', 'treated_at' => now()]),
        'update' => $treatment->update(['notes' => 'Geändert']),
        'delete' => $treatment->delete(),
        'reparent' => $treatment->update(['item_id' => $this->itemB->id]),
    };
    expect($mutate)->toThrow(HttpException::class);
    expect($this->access->asActor($this->admin, fn () => ItemTreatment::count()))->toBe(1);
    config(['locations.clothing_personal_enabled' => false]);
    expect(ItemTreatment::count())->toBe(1);
})->with(['create', 'update', 'delete', 'reparent']);

it('does not partially complete washing when the required treatment is global only', function () {
    Notification::fake();
    $messages = app(EquipmentMessageService::class)->requestWashing($this->shared, [$this->issuedA->id], 'Nach Einsatz', actor: $this->shared);
    $this->actingAs($this->worker);
    Livewire::test(ListEquipmentMessages::class)->assertTableActionDisabled('washed', $messages->first());
    expect(fn () => app(EquipmentMessageService::class)->markWashed($messages->first(), $this->worker))->toThrow(HttpException::class);
    expect(DB::table('kk_equipment_messages')->where('id', $messages->first()->id)->value('handled_at'))->toBeNull();
    expect(DB::table('kk_item_treatments')->count())->toBe(0);
    Notification::assertNotSentTo($this->shared, WashingDoneNotification::class);
    $this->actingAs($this->admin);
    Livewire::test(ListEquipmentMessages::class)->assertTableActionEnabled('washed', $messages->first());
    app(EquipmentMessageService::class)->markWashed($messages->first(), $this->admin);
    expect(DB::table('kk_equipment_messages')->where('id', $messages->first()->id)->value('handled_at'))->not->toBeNull();
    expect(DB::table('kk_item_treatments')->count())->toBe(1);
    Notification::assertSentTo($this->shared, WashingDoneNotification::class);
});

it('keeps scoped bulk washing completion available without writing treatment histories', function () {
    Notification::fake();
    $messages = app(EquipmentMessageService::class)->requestWashing($this->shared, [$this->bulkA->id], 'Nach Einsatz', actor: $this->shared);
    $this->actingAs($this->worker);
    Livewire::test(ListEquipmentMessages::class)->assertTableActionEnabled('washed', $messages->first())
        ->callTableAction('washed', $messages->first());
    expect(DB::table('kk_equipment_messages')->where('id', $messages->first()->id)->value('handled_at'))->not->toBeNull();
    expect(DB::table('kk_item_treatments')->count())->toBe(0);
    Notification::assertSentTo($this->shared, WashingDoneNotification::class);
});

it('keeps both clothing capabilities unique and refuses rollback without changing grant history', function () {
    $migration = require base_path('extensions/Locations/database/migrations/2026_12_27_000000_separate_clothing_capability_grants.php');
    $before = DB::table('x_location_module_roles')->orderBy('id')->get()->toJson();
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    expect(DB::table('x_location_module_roles')->orderBy('id')->get()->toJson())->toBe($before);
    $grant = DB::table('x_location_module_roles')->where('user_id', $this->combined->id)->where('role', 'bestand')->first();
    expect(fn () => DB::transaction(fn () => DB::table('x_location_module_roles')->insert(
        array_diff_key((array) $grant, ['id' => true])
    )))->toThrow(QueryException::class);
});

it('round trips the capability migration without rewriting any prior grant', function () {
    $migration = require base_path('extensions/Locations/database/migrations/2026_12_27_000000_separate_clothing_capability_grants.php');
    $personal = DB::table('x_location_module_roles')->where('user_id', $this->combined->id)->where('role', 'kleiderwart')->first();
    $this->roles->revoke($this->admin, $personal->id, $this->a->id, 'Teilaufgabe beendet');
    $before = DB::table('x_location_module_roles')->orderBy('id')->get()->toJson();
    $migration->down();
    $migration->up();
    expect(DB::table('x_location_module_roles')->orderBy('id')->get()->toJson())->toBe($before);
    $this->roles->grant($this->admin, $this->a->id, $this->combined->id, 'kleiderkammer', 'kleiderwart', 'Neu übernehmen');
    expect(DB::table('x_location_module_roles')->where('user_id', $this->combined->id)->whereNull('revoked_at')->count())->toBe(2);
});

it('uses the explicit global import actor while filtering foreign item treatments', function () {
    $this->actingAs($this->worker);
    app(DataTransferService::class)->import(['groups' => ['inventory' => ['treatments' => [
        ['item_code' => $this->itemB->code, 'type' => 'washing', 'treated_at' => now()->toDateString(), 'notes' => 'Importiert'],
    ]]]], ['treatments'], ['Jacke'], actor: $this->admin);
    expect(DB::table('kk_item_treatments')->where('item_id', $this->itemB->id)->count())->toBe(1);
    expect(ItemTreatment::count())->toBe(0);
});

it('retains the existing location filter in scan queries with personal protection off', function () {
    config(['locations.clothing_personal_enabled' => false]);
    $this->actingAs($this->legacy);
    expect(app(CodeResolver::class)->resolve($this->itemB->code))->toBeNull()
        ->and(app(CodeResolver::class)->resolveActive($this->itemB->code))->toBeNull()
        ->and(app(CodeResolver::class)->resolve($this->itemA->code)?->id)->toBe($this->itemA->id);
});

it('preserves access to a members own equipment across locations without management authority', function () {
    $this->actingAs($this->shared);
    expect($this->shared->kleiderkammerItems()->pluck('id')->all())->toContain($this->issuedB->id, $this->unknownIssued->id)
        ->and(Item::whereKey($this->itemB->id)->exists())->toBeTrue()
        ->and($this->access->canManageIssuance($this->shared, $this->issuedB))->toBeFalse();
});

it('rejects notification payloads with mismatched message and issuance owners', function (string $kind) {
    $message = clone $this->messageA;
    $message->user_id = $this->foreign->id;
    $notice = $kind === 'defect'
        ? new EquipmentMessageNotification($message)
        : new WashingRequestedNotification($this->foreign, collect([$message]));
    expect(fn () => $notice->toMail($this->worker))->toThrow(HttpException::class);
})->with(['defect', 'washing']);

it('preserves default-off location boundaries in tracked service mutations', function (string $operation) {
    config(['locations.clothing_personal_enabled' => false]);
    $this->actingAs($this->legacy);
    $change = fn () => match ($operation) {
        'issue-source' => app(IssuingService::class)->issueTracked($this->shared, $this->spareB),
        'issue-recipient' => app(IssuingService::class)->issueTracked($this->foreign, $this->spareA),
        'retire' => app(IssuingService::class)->retire($this->spareB),
        'return' => app(IssuingService::class)->return($this->issuedB),
        'take-back' => app(IssuingService::class)->takeBack($this->shared),
        'bulk-recipient' => app(IssuingService::class)->issueStock($this->foreign, $this->bulkType, null, 1, stockId: $this->stockA->id),
    };
    expect($change)->toThrow(ModelNotFoundException::class);
    expect(DB::table('kk_items')->where('id', $this->spareB->id)->value('status'))->toBe('available')
        ->and(DB::table('kk_items')->where('id', $this->spareA->id)->value('status'))->toBe('available')
        ->and(DB::table('kk_items')->where('id', $this->itemB->id)->value('status'))->toBe('issued')
        ->and(DB::table('kk_issued_items')->where('id', $this->issuedB->id)->value('returned_at'))->toBeNull()
        ->and(DB::table('kk_issued_items')->where('id', $this->issuedA->id)->value('returned_at'))->toBeNull()
        ->and(DB::table('kk_stock')->where('id', $this->stockA->id)->value('quantity'))->toBe(20);
})->with(['issue-source', 'issue-recipient', 'retire', 'return', 'take-back', 'bulk-recipient']);

it('does not expose a foreign current item to a former holder', function () {
    $this->actingAs($this->shared);
    expect(Item::whereKey($this->itemB->id)->exists())->toBeTrue();
    DB::table('kk_issued_items')->where('id', $this->issuedB->id)->update(['returned_at' => now()]);
    expect(Item::whereKey($this->itemB->id)->exists())->toBeFalse()
        ->and($this->issuedB->fresh()->item)->toBeNull();
    expect(IssuedItem::whereKey($this->issuedB->id)->exists())->toBeTrue();
});

it('separates management inventory from a managers own foreign issued equipment', function () {
    DB::table('kk_issued_items')->where('id', $this->issuedB->id)->update(['user_id' => $this->worker->id]);
    $this->actingAs($this->worker);
    expect(Item::whereKey($this->itemB->id)->exists())->toBeTrue()
        ->and($this->worker->kleiderkammerItems()->whereKey($this->issuedB->id)->exists())->toBeTrue()
        ->and(ItemResource::getEloquentQuery()->whereKey($this->itemB->id)->exists())->toBeFalse();
    Livewire::test(ListItems::class)->assertCanSeeTableRecords([$this->itemA])
        ->assertCanNotSeeTableRecords([$this->itemB]);
    expect(app(InventoryReport::class)->trackedByType()->sum('total'))->toBe(2);
});

it('independently scopes member counters and the has-items filter to permitted source history', function () {
    $this->actingAs($this->worker);
    Livewire::test(ListMembers::class)->assertTableColumnStateSet('kleiderkammer_items_count', 2, $this->shared);
    DB::table('kk_issued_items')->whereIn('id', [$this->issuedA->id, $this->bulkA->id])->update(['returned_at' => now()]);
    Livewire::test(ListMembers::class)->assertTableColumnStateSet('kleiderkammer_items_count', 0, $this->shared)
        ->filterTable('has_items')->assertCanNotSeeTableRecords([$this->shared]);
    expect(DB::table('kk_issued_items')->where('user_id', $this->shared->id)->whereNull('returned_at')->count())->toBe(4);
});

it('does not disclose a foreign member through a known physical items current issuance', function () {
    $this->actingAs($this->worker);
    expect($this->itemA->currentIssuance()->count())->toBe(1);
    DB::table('kk_issued_items')->where('id', $this->issuedA->id)->update(['user_id' => $this->foreign->id]);
    expect($this->itemA->currentIssuance()->count())->toBe(0)
        ->and($this->itemA->currentIssuance()->with('user')->get())->toBeEmpty()
        ->and(app(ScanRegistry::class)->resolveKnown('PERSONAL-A'))->not->toContain('/kameraden/');
});

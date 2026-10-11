<?php

use App\Enums\MemberStatus;
use App\Models\Group;
use App\Models\Module;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Kleiderkammer\src\Filament\Pages\DataTransferPage;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemCategory;
use Modules\Kleiderkammer\src\Models\ItemTreatment;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\MemberSize;
use Modules\Kleiderkammer\src\Models\Size;
use Modules\Kleiderkammer\src\Models\Stock;
use Modules\Kleiderkammer\src\Services\DataTransferService;

function seedCatalog(): array
{
    $cat = ItemCategory::create(['name' => 'Schutzkleidung', 'sort_order' => 1]);
    $type = ItemType::create(['name' => 'Einsatzjacke', 'category_id' => $cat->id, 'code_prefix' => 'EJA', 'is_tracked' => true, 'has_size' => true, 'is_washable' => true, 'sort_order' => 1]);
    $size = Size::create(['item_type_id' => $type->id, 'name' => 'M', 'sort_order' => 0]);

    return compact('cat', 'type', 'size');
}

// ─── export ──────────────────────────────────────────────────────────────────

test('export contains a version and exported_at timestamp', function () {
    $result = app(DataTransferService::class)->export();

    expect($result['version'])->toBe(1);
    expect($result)->toHaveKey('exported_at');
});

test('export catalog group contains categories, item types and sizes', function () {
    seedCatalog();

    $result = app(DataTransferService::class)->export(['catalog']);

    expect($result['groups']['catalog']['categories'])->toHaveCount(1);
    expect($result['groups']['catalog']['item_types'])->toHaveCount(1);
    expect($result['groups']['catalog']['sizes'])->toHaveCount(1);
    expect($result['groups'])->not->toHaveKey('members');
    expect($result['groups'])->not->toHaveKey('inventory');
});

test('export members group contains users with their member data', function () {
    $hanna = User::factory()->create([
        'name' => 'Hanna Hydrant', 'email' => 'hanna.hydrant@example.com',
        'rank' => 'Jugendwartin', 'status' => MemberStatus::Left,
    ]);
    $hanna->groups()->attach(Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 1]));

    $row = collect(app(DataTransferService::class)->export(['members'])['groups']['members']['members'])
        ->firstWhere('email', 'hanna.hydrant@example.com');

    expect($row)->toMatchArray(['name' => 'Hanna Hydrant', 'departments' => ['Jugendfeuerwehr'], 'role' => 'Jugendwartin', 'status' => 'left']);
});

test('export inventory group includes soft-deleted items', function () {
    ['type' => $type] = seedCatalog();
    $item = Item::create(['item_type_id' => $type->id, 'code' => 'WEHREJA0001', 'status' => 'available']);
    $item->delete();

    $result = app(DataTransferService::class)->export(['inventory']);

    $codes = array_column($result['groups']['inventory']['items'], 'code');
    expect($codes)->toContain('WEHREJA0001');
});

// ─── import round-trip ────────────────────────────────────────────────────────

test('catalog import is idempotent — re-running does not duplicate rows', function () {
    $service = app(DataTransferService::class);
    seedCatalog();

    $exported = $service->export(['catalog']);
    $service->import($exported);
    $service->import($exported); // second run

    expect(ItemCategory::count())->toBe(1);
    expect(ItemType::count())->toBe(1);
    expect(Size::count())->toBe(1);
});

test('member import creates users, maps soft-deleted members to left and reports members without e-mail', function () {
    $report = app(DataTransferService::class)->import(['groups' => ['members' => ['members' => [
        ['id' => 1, 'name' => 'Hanna Hydrant', 'email' => 'hanna.hydrant@example.com', 'department' => 'active_service', 'role' => 'Gruppenführerin', 'gender' => 'female', 'status' => 'active'],
        ['id' => 2, 'name' => 'Bernd Blaulicht', 'email' => 'bernd.blaulicht@example.com', 'status' => 'active', 'deleted_at' => '2026-01-01T00:00:00Z'],
        ['id' => 3, 'name' => 'Klara Lösch', 'email' => null, 'status' => 'active'],
    ]]]]);

    $hanna = User::where('email', 'hanna.hydrant@example.com')->first();
    expect($hanna->rank)->toBe('Gruppenführerin');
    expect($hanna->groups->pluck('name')->all())->toBe(['Aktiver Dienst']);
    expect($hanna->is_active)->toBeTrue();

    $bernd = User::where('email', 'bernd.blaulicht@example.com')->first();
    expect($bernd->status)->toBe(MemberStatus::Left);
    expect($bernd->is_active)->toBeFalse();

    expect($report['skipped_members'])->toBe(['Klara Lösch']);
    expect(User::where('name', 'Klara Lösch')->exists())->toBeFalse();
});

test('member import matches existing users by e-mail and keeps their name and login status', function () {
    $existing = User::factory()->admin()->create(['name' => 'Hanna H.', 'email' => 'hanna.hydrant@example.com']);

    app(DataTransferService::class)->import(['groups' => ['members' => ['members' => [
        ['id' => 1, 'name' => 'Hanna Hydrant', 'email' => 'Hanna.Hydrant@example.com', 'role' => 'Gruppenführerin', 'status' => 'left'],
    ]]]]);

    $existing->refresh();
    expect(User::count())->toBe(1);
    expect($existing->name)->toBe('Hanna H.');
    expect($existing->rank)->toBe('Gruppenführerin');
    expect($existing->status)->toBe(MemberStatus::Active);
});

test('import of an old-app export resolves all references through the IDs in the file', function () {
    // IDs from the old SQLite database, deliberately unrelated to the IDs here.
    $payload = ['version' => 1, 'groups' => [
        'catalog' => [
            'categories' => [['id' => 40, 'name' => 'Schutzkleidung', 'sort_order' => 1]],
            'item_types' => [
                ['id' => 50, 'category_id' => 40, 'name' => 'Einsatzjacke', 'code_prefix' => 'EJA', 'is_tracked' => true, 'has_size' => true, 'is_washable' => true, 'sort_order' => 1],
                ['id' => 51, 'category_id' => 40, 'name' => 'Handschuhe', 'code_prefix' => null, 'is_tracked' => false, 'has_size' => true, 'is_washable' => false, 'sort_order' => 2],
            ],
            'sizes' => [
                ['id' => 70, 'item_type_id' => 50, 'name' => 'L', 'sort_order' => 1],
                ['id' => 71, 'item_type_id' => 51, 'name' => '10', 'sort_order' => 1],
            ],
        ],
        'members' => ['members' => [
            ['id' => 9, 'name' => 'Hanna Hydrant', 'email' => 'hanna.hydrant@example.com', 'status' => 'active'],
            ['id' => 10, 'name' => 'Klara Lösch', 'email' => '', 'status' => 'active'],
        ]],
        'inventory' => [
            'items' => [['id' => 30, 'item_type_id' => 50, 'size_id' => 70, 'code' => 'WEHREJA0001', 'status' => 'issued', 'notes' => null]],
            'stock' => [['id' => 5, 'item_type_id' => 51, 'size_id' => 71, 'quantity' => 12]],
            'issued_items' => [
                ['id' => 1, 'member_id' => 9, 'item_id' => 30, 'item_type_id' => null, 'size_id' => null, 'quantity' => 1, 'issued_at' => '2026-03-01T10:00:00Z', 'returned_at' => null],
                ['id' => 2, 'member_id' => 9, 'item_id' => null, 'item_type_id' => 51, 'size_id' => 71, 'quantity' => 2, 'issued_at' => '2026-03-02T10:00:00Z', 'returned_at' => null],
                ['id' => 3, 'member_id' => 10, 'item_id' => null, 'item_type_id' => 51, 'size_id' => 71, 'quantity' => 1, 'issued_at' => '2026-03-03T10:00:00Z', 'returned_at' => null],
            ],
            'treatments' => [['id' => 1, 'item_id' => 30, 'type' => 'washing', 'treated_at' => '2026-02-01', 'notes' => null]],
        ],
    ]];

    $service = app(DataTransferService::class);
    $report = $service->import($payload);
    $service->import($payload); // idempotent

    $hanna = User::where('email', 'hanna.hydrant@example.com')->first();
    $jacket = Item::where('code', 'WEHREJA0001')->first();

    expect($jacket->size->name)->toBe('L');
    expect(Stock::whereHas('size', fn ($q) => $q->where('name', '10'))->value('quantity'))->toBe(12);
    expect($hanna->kleiderkammerItems()->count())->toBe(2);
    expect($hanna->kleiderkammerItems()->whereNotNull('item_id')->value('item_id'))->toBe($jacket->id);
    expect($hanna->kleiderkammerItems()->whereNull('item_id')->first()->size->name)->toBe('10');
    expect(IssuedItem::count())->toBe(2);
    expect(ItemTreatment::where('item_id', $jacket->id)->count())->toBe(1);
    expect($report['issued_items'])->toBe(2);
    expect($report['skipped_issued_items'])->toBe(1);
    expect($report['skipped_members'])->toBe(['Klara Lösch']);
});

test('full round-trip preserves catalog, items, stock and issuances', function () {
    $service = app(DataTransferService::class);
    ['type' => $type, 'size' => $size] = seedCatalog();
    Stock::create(['item_type_id' => $type->id, 'size_id' => $size->id, 'quantity' => 5]);
    $hanna = User::factory()->create(['name' => 'Hanna Hydrant']);
    $item = Item::create(['item_type_id' => $type->id, 'size_id' => $size->id, 'code' => 'WEHREJA0001', 'status' => 'issued']);
    IssuedItem::create(['user_id' => $hanna->id, 'item_id' => $item->id, 'quantity' => 1, 'issued_at' => now()]);

    MemberSize::create(['user_id' => $hanna->id, 'item_type_id' => $type->id, 'size_id' => $size->id]);

    $exported = $service->export();

    // Wipe the Kleiderkammer data; users stay, they are matched by e-mail.
    MemberSize::query()->delete();
    IssuedItem::withTrashed()->forceDelete();
    Item::withTrashed()->forceDelete();
    Stock::query()->delete();
    Size::query()->delete();
    ItemType::query()->delete();
    ItemCategory::query()->delete();

    $service->import($exported);

    expect(ItemCategory::count())->toBe(1);
    expect(ItemType::count())->toBe(1);
    expect(Size::count())->toBe(1);
    expect(Item::first()->size->name)->toBe('M');
    expect(Stock::where('quantity', 5)->exists())->toBeTrue();
    expect($hanna->kleiderkammerItems()->count())->toBe(1);
    expect($hanna->kleiderkammerSizes()->first()->size->name)->toBe('M');
});

// ─── upload page ─────────────────────────────────────────────────────────────

test('admin can import an uploaded export file on the data transfer page', function () {
    Storage::fake('local');
    Module::where('key', 'kleiderkammer')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Filament::setCurrentPanel(Filament::getPanel('kleiderkammer'));

    $json = json_encode(['version' => 1, 'groups' => ['members' => ['members' => [
        ['name' => 'Hanna Hydrant', 'email' => 'hanna.hydrant@example.com'],
    ]]]]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(DataTransferPage::class)
        ->fillForm(['file' => UploadedFile::fake()->createWithContent('export.json', $json)], 'importForm')
        ->set('importData.parts', ['members'])
        ->call('import')
        ->assertNotified('Import erfolgreich');

    expect(User::where('email', 'hanna.hydrant@example.com')->exists())->toBeTrue();
    expect(Storage::disk('local')->allFiles('kleiderkammer-imports'))->toBeEmpty();
});

test('the export button downloads the JSON file', function () {
    Module::where('key', 'kleiderkammer')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Filament::setCurrentPanel(Filament::getPanel('kleiderkammer'));
    seedCatalog();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(DataTransferPage::class)
        ->set('exportData.parts', ['categories', 'item_types'])
        ->call('export')
        ->assertFileDownloaded('kleiderkammer-export-'.now()->format('Y-m-d').'.json');
});

test('the page is titled Datentransfer', function () {
    expect((new ReflectionMethod(DataTransferPage::class, 'getTitle'))->invoke(new DataTransferPage))->toBe('Datentransfer');
});

// ─── selection ───────────────────────────────────────────────────────────────

function seedTwoTypes(): array
{
    ['type' => $jacket, 'size' => $size] = seedCatalog();
    $helmet = ItemType::create(['name' => 'Helm', 'code_prefix' => 'HEL', 'is_tracked' => true, 'sort_order' => 2]);
    Item::create(['item_type_id' => $jacket->id, 'size_id' => $size->id, 'code' => 'WEHREJA0001', 'status' => 'available']);
    Item::create(['item_type_id' => $helmet->id, 'code' => 'WEHRHEL0001', 'status' => 'available']);
    Stock::create(['item_type_id' => $jacket->id, 'size_id' => $size->id, 'quantity' => 4]);
    User::factory()->create(['name' => 'Hanna Hydrant', 'email' => 'hanna.hydrant@example.com']);

    return compact('jacket', 'helmet');
}

test('an export of chosen parts contains no member data', function () {
    seedTwoTypes();

    $result = app(DataTransferService::class)->export(['categories', 'item_types', 'items', 'stock']);

    expect($result['groups'])->toHaveKeys(['catalog', 'inventory'])->not->toHaveKey('members');
    expect($result['groups']['inventory'])->toHaveKeys(['items', 'stock'])->not->toHaveKeys(['issued_items', 'treatments']);
    expect(json_encode($result))->not->toContain('hanna.hydrant@example.com');
});

test('exporting issuances brings the members along', function () {
    seedTwoTypes();

    $result = app(DataTransferService::class)->export(['issuances']);

    expect($result['groups'])->toHaveKey('members');
});

test('inspect lists the parts and item types of a file', function () {
    seedTwoTypes();
    $info = app(DataTransferService::class)->inspect(app(DataTransferService::class)->export());

    expect($info['parts'])->toMatchArray(['categories' => 1, 'item_types' => 2, 'items' => 2, 'stock' => 1]);
    expect($info['types'])->toBe(['Einsatzjacke', 'Helm']);
});

test('import takes only the selected parts and item types', function () {
    seedTwoTypes();
    $service = app(DataTransferService::class);
    $file = $service->export();

    Item::withTrashed()->forceDelete();
    Stock::query()->delete();
    Size::query()->delete();
    ItemType::query()->delete();
    ItemCategory::query()->delete();
    User::where('email', 'hanna.hydrant@example.com')->delete();

    $service->import($file, ['item_types', 'items'], ['Helm']);

    expect(ItemType::pluck('name')->all())->toBe(['Helm']);
    expect(Item::pluck('code')->all())->toBe(['WEHRHEL0001']);
    expect(Stock::count())->toBe(0);
    expect(ItemCategory::count())->toBe(0);
    expect(User::where('email', 'hanna.hydrant@example.com')->exists())->toBeFalse();
});

test('importing the catalog without categories keeps the category by name if it exists', function () {
    seedTwoTypes();
    $service = app(DataTransferService::class);
    $file = $service->export(['item_types']);

    Item::withTrashed()->forceDelete();
    Stock::query()->delete();
    Size::query()->delete();
    ItemType::query()->delete();

    $service->import($file, ['item_types']);

    expect(ItemType::where('name', 'Einsatzjacke')->first()->category?->name)->toBe('Schutzkleidung');
});

test('member import adds the named Abteilungen and keeps the existing ones', function () {
    $hanna = User::factory()->create(['email' => 'hanna.hydrant@example.com']);
    $hanna->groups()->attach(Group::create(['name' => 'Musikzug', 'sort_order' => 1]));

    app(DataTransferService::class)->import(['groups' => ['members' => ['members' => [
        ['id' => 1, 'name' => 'Hanna Hydrant', 'email' => 'hanna.hydrant@example.com', 'departments' => ['Jugendfeuerwehr', 'Musikzug']],
    ]]]]);

    expect($hanna->refresh()->groups->pluck('name')->sort()->values()->all())->toBe(['Jugendfeuerwehr', 'Musikzug']);
    expect(Group::where('name', 'Musikzug')->count())->toBe(1);
});

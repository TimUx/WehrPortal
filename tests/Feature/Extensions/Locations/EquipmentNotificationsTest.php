<?php

use App\Models\User;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Models\StandardRoleAssignment;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Ausruestung\src\Filament\Pages\LoadPlanPage;
use Modules\Ausruestung\src\Filament\Pages\PublicScanPage;
use Modules\Ausruestung\src\Filament\Resources\CategoryResource;
use Modules\Ausruestung\src\Filament\Resources\LocationResource;
use Modules\Ausruestung\src\Filament\Resources\RecallResource;
use Modules\Ausruestung\src\Filament\Resources\RecallResource\Pages\EditRecall;
use Modules\Ausruestung\src\Filament\Resources\RecallResource\Pages\ListRecalls;
use Modules\Ausruestung\src\Filament\Resources\RecallResource\RelationManagers\AffectedEquipmentRelationManager;
use Modules\Ausruestung\src\Models\Location as StorageLocation;
use Modules\Ausruestung\src\Models\Recall;
use Modules\Ausruestung\src\Notifications\DamageReportedNotification;
use Modules\Ausruestung\src\Notifications\MaintenanceDigestNotification;
use Modules\Ausruestung\src\Notifications\RecallMatchedNotification;
use Modules\Ausruestung\src\Services\EquipmentService;
use Modules\Ausruestung\src\Services\RecallService;
use Modules\Ausruestung\src\Services\ReminderService;

require_once __DIR__.'/../../Ausruestung/Helpers.php';

beforeEach(function () {
    config(['locations.enabled' => true]);
    activateAusruestung();
    Cache::forget('modules_active_keys');
    Notification::fake();

    $this->admin = User::factory()->admin()->create();
    $this->north = Location::create(['name' => 'Wache Nord', 'slug' => 'ausruestung-nord']);
    $this->south = Location::create(['name' => 'Wache Süd', 'slug' => 'ausruestung-sued']);
    $this->northWarden = User::factory()->create(['ext_location_id' => $this->north->id]);
    $this->southWarden = User::factory()->create(['ext_location_id' => $this->south->id]);

    foreach ([[$this->northWarden, $this->north], [$this->southWarden, $this->south]] as [$warden, $location]) {
        StandardRoleAssignment::create([
            'user_id' => $warden->id,
            'profile' => 'geraetewart',
            'location_id' => $location->id,
            'group_id' => null,
            'granted_by' => $this->admin->id,
            'granted_at' => now()->subMinute(),
            'reason' => 'Test assignment',
        ]);
    }
});

it('sends damage notices only to the Gerätewart for the equipment Standort', function () {
    $item = equipment(['code' => 'NORD-001', 'ext_location_id' => $this->north->id]);

    app(EquipmentService::class)->reportDamage($item, $this->admin, 'Schutzvisier beschädigt');

    Notification::assertSentTo($this->northWarden, DamageReportedNotification::class);
    Notification::assertNotSentTo($this->southWarden, DamageReportedNotification::class);
});

it('limits each local maintenance digest to equipment at that Standort', function () {
    $northItem = equipment(['code' => 'NORD-PRUEFUNG', 'ext_location_id' => $this->north->id, 'next_inspection_at' => today()->addDays(5)]);
    $southItem = equipment(['code' => 'SUED-PRUEFUNG', 'ext_location_id' => $this->south->id, 'next_inspection_at' => today()->addDays(5)]);

    expect(app(ReminderService::class)->sendDigest())->toBeTrue();

    Notification::assertSentTo($this->northWarden, MaintenanceDigestNotification::class, function (MaintenanceDigestNotification $notification) use ($northItem, $southItem) {
        $mail = $notification->toMail($this->northWarden)->render();

        return str_contains($mail, $northItem->code)
            && ! str_contains($mail, $southItem->code)
            && str_contains($mail, 'Zur Ausrüstungsverwaltung');
    });
});

it('sends each recall match only to local Gerätewarte for the matching Standort', function () {
    $northItem = equipment(['code' => 'NORD-RUECKRUF', 'ext_location_id' => $this->north->id]);
    $southItem = equipment(['code' => 'SUED-RUECKRUF', 'ext_location_id' => $this->south->id]);
    $recall = Recall::create(['title' => 'Prüfung Kinnriemen', 'manufacturer' => 'Dräger', 'model' => 'HPS 7000']);

    app(RecallService::class)->apply($recall, $this->admin);

    Notification::assertSentTo($this->northWarden, RecallMatchedNotification::class, function (RecallMatchedNotification $notification) use ($northItem, $southItem) {
        $mail = $notification->toMail($this->northWarden)->render();

        return str_contains($mail, $northItem->code) && ! str_contains($mail, $southItem->code);
    });
    Notification::assertSentTo($this->southWarden, RecallMatchedNotification::class, function (RecallMatchedNotification $notification) use ($northItem, $southItem) {
        $mail = $notification->toMail($this->southWarden)->render();

        return str_contains($mail, $southItem->code)
            && ! str_contains($mail, $northItem->code)
            && str_contains($mail, 'Ausrüstung öffnen');
    });
});

it('lets local Gerätewarte resolve only their own recall matches', function () {
    $this->northWarden->moduleAccess()->create(['module' => 'ausruestung', 'role' => null]);
    $northItem = equipment(['code' => 'NORD-RUECKRUF', 'ext_location_id' => $this->north->id]);
    $southItem = equipment(['code' => 'SUED-RUECKRUF', 'ext_location_id' => $this->south->id]);
    $recall = Recall::create(['title' => 'Prüfung Kinnriemen', 'manufacturer' => 'Dräger', 'model' => 'HPS 7000']);
    app(RecallService::class)->apply($recall, $this->admin);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->northWarden);

    expect(RecallResource::canAccess())->toBeTrue()
        ->and(RecallResource::canCreate())->toBeFalse()
        ->and(RecallResource::canEdit($recall))->toBeTrue();

    Livewire::actingAs($this->northWarden)
        ->test(ListRecalls::class)
        ->assertCanSeeTableRecords([$recall])
        ->assertTableColumnStateSet('affected_count', 1, $recall)
        ->assertTableColumnStateSet('open_count', 1, $recall);

    Livewire::actingAs($this->northWarden)
        ->test(EditRecall::class, ['record' => $recall->id])
        ->set('data.title', 'Fremder Titel')
        ->call('save')
        ->assertForbidden();
    expect($recall->fresh()->title)->toBe('Prüfung Kinnriemen');

    Livewire::actingAs($this->northWarden)
        ->test(AffectedEquipmentRelationManager::class, ['ownerRecord' => $recall, 'pageClass' => EditRecall::class])
        ->assertSee($northItem->code)
        ->assertDontSee($southItem->code);

    app(RecallService::class)->resolve($recall, $northItem, $this->northWarden, 'Geprüft');
    expect($northItem->refresh()->status->value)->toBe('available');

    expect(fn () => app(RecallService::class)->resolve($recall, $southItem, $this->northWarden, 'Fremder Standort'))
        ->toThrow(ValidationException::class);
    expect(fn () => app(RecallService::class)->apply($recall, $this->northWarden))
        ->toThrow(ValidationException::class);
    expect(fn () => app(RecallService::class)->close($recall, $this->northWarden))
        ->toThrow(ValidationException::class);
});

it('lets a local Gerätewart manage only the storage places at their Standort', function () {
    $this->actingAs($this->admin);
    $northStorage = StorageLocation::create([
        'name' => 'Nordlager',
        'kind' => StorageLocation::ROOM,
        'ext_location_id' => $this->north->id,
    ]);
    $southStorage = StorageLocation::create([
        'name' => 'Südlager',
        'kind' => StorageLocation::ROOM,
        'ext_location_id' => $this->south->id,
    ]);
    auth()->login($this->northWarden);

    expect(CategoryResource::canAccess())->toBeFalse()
        ->and(LocationResource::canAccess())->toBeTrue()
        ->and(LocationResource::getEloquentQuery()->pluck('id')->all())->toBe([$northStorage->id])
        ->and(LocationResource::getEloquentQuery()->pluck('id')->all())->not->toContain($southStorage->id)
        ->and(LoadPlanPage::canAccess())->toBeTrue()
        ->and(RecallResource::canAccess())->toBeTrue()
        ->and(RecallResource::canCreate())->toBeFalse()
        ->and(PublicScanPage::canAccess())->toBeFalse();
});

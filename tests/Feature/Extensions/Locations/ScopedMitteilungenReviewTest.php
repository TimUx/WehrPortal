<?php

use App\Models\Module;
use App\Models\User;
use App\Services\ModuleRegistry;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\LocationModuleRoles;
use Extensions\Locations\Services\ScopedAnnouncementService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\Mitteilungen\src\Livewire\Manage;
use Modules\Mitteilungen\src\Models\Announcement;
use Modules\Mitteilungen\src\Notifications\AnnouncementNotification;
use Modules\Mitteilungen\src\Services\AnnouncementService;

beforeEach(function () {
    config(['locations.scoped_role_modules' => ['mitteilungen']]);
    app()->singleton(AnnouncementService::class, ScopedAnnouncementService::class);
    Module::where('key', 'mitteilungen')->update(['is_active' => true, 'auto_assign' => false]);
    app(ModuleRegistry::class)->clearCache();
    $this->admin = User::factory()->admin()->create();
    $this->location = Location::create(['name' => 'Wache Nord', 'slug' => 'form-nord']);
    $this->editor = User::factory()->create(['ext_location_id' => $this->location->id]);
    app(LocationModuleRoles::class)->grant(
        $this->admin, $this->location->id, $this->editor->id, 'mitteilungen', 'redakteur', 'Redaktion ausdrücklich übertragen',
    );
});

it('restores an authorized target after cancelling or saving a scoped announcement', function (string $action) {
    $component = Livewire::actingAs($this->editor)->test(Manage::class)
        ->set('title', 'Erste Mitteilung')->set('body', 'Information für den Standort');
    $component->call($action)->assertSuccessful()
        ->assertSet('editingId', null)
        ->assertSet('locationId', (string) $this->location->id);
    $component->set('title', 'Nächste Mitteilung')->call('save')->assertSuccessful();
    expect(Announcement::where('title', 'Nächste Mitteilung')->value('ext_location_id'))->toBe($this->location->id);
})->with(['cancel', 'save']);

it('clears editing and statistics state after deleting the edited announcement', function (bool $scoped) {
    if (! $scoped) {
        config(['locations.scoped_role_modules' => []]);
        app()->singleton(AnnouncementService::class, AnnouncementService::class);
    }
    $announcement = Announcement::create([
        'title' => 'Zu löschen', 'created_by' => $this->admin->id, 'ext_location_id' => $this->location->id,
    ]);
    Livewire::actingAs($scoped ? $this->editor : $this->admin)->test(Manage::class)
        ->call('edit', $announcement->id)->call('showStats', $announcement->id)
        ->call('delete', $announcement->id)->assertSuccessful()
        ->assertSet('editingId', null)->assertSet('statsFor', null);
    expect(Announcement::whereKey($announcement->id)->exists())->toBeFalse();
})->with([true, false]);

it('intersects explicit mail recipients with fresh location and module eligibility', function () {
    Notification::fake();
    $recipient = User::factory()->create(['ext_location_id' => $this->location->id]);
    $recipient->moduleAccess()->create(['module' => 'mitteilungen']);
    $outsider = User::factory()->create();
    $outsider->moduleAccess()->create(['module' => 'mitteilungen']);
    $announcement = Announcement::create([
        'title' => 'Nur am Standort', 'created_by' => $this->editor->id, 'ext_location_id' => $this->location->id,
    ]);
    $service = app(AnnouncementService::class);
    $requested = new EloquentCollection([$recipient, $outsider]);
    expect($service->mail($announcement, to: $requested, by: $this->editor))->toBe(1);
    Notification::assertSentTo($recipient, AnnouncementNotification::class);
    Notification::assertNotSentTo($outsider, AnnouncementNotification::class);
    $recipient->moduleAccess()->delete();
    Notification::fake();
    expect($service->mail($announcement, to: $requested, by: $this->editor))->toBe(0);
    Notification::assertNothingSent();
});

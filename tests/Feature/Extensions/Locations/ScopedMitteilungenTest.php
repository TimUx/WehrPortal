<?php

use App\Models\Group;
use App\Models\MemberFunction;
use App\Models\Module;
use App\Models\User;
use App\Services\ModuleRegistry;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Models\LocationModuleRole;
use Extensions\Locations\Services\LocationModuleRoles;
use Extensions\Locations\Services\ScopedAnnouncementService;
use Extensions\Mitteilungen\Models\ChannelDelivery;
use Extensions\Mitteilungen\Services\AnnouncementDelivery;
use Extensions\Mitteilungen\Services\ChannelSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Mitteilungen\src\Livewire\Manage;
use Modules\Mitteilungen\src\Models\Announcement;
use Modules\Mitteilungen\src\Models\AnnouncementFile;
use Modules\Mitteilungen\src\Services\AnnouncementService;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config(['locations.scoped_role_modules' => ['mitteilungen']]);
    app()->singleton(AnnouncementService::class, ScopedAnnouncementService::class);
    Module::where('key', 'mitteilungen')->update(['is_active' => true, 'auto_assign' => false]);
    app(ModuleRegistry::class)->clearCache();
    $this->service = app(AnnouncementService::class);
    $this->roles = app(LocationModuleRoles::class);
    $this->admin = User::factory()->admin()->create();
    $this->a = Location::create(['name' => 'Wache Nord', 'slug' => 'editor-nord']);
    $this->b = Location::create(['name' => 'Wache Süd', 'slug' => 'editor-sued']);
    $this->editor = User::factory()->create(['ext_location_id' => $this->a->id]);
    DB::table('x_location_user')->insert(['location_id' => $this->b->id, 'user_id' => $this->editor->id, 'role' => 'admin']);
    $this->editor->moduleAccess()->create(['module' => 'mitteilungen', 'role' => 'redakteur']);
    $this->grant = fn (?Location $at = null, ?string $expires = null) => $this->roles->grant(
        $this->admin, ($at ?? $this->a)->id, $this->editor->id, 'mitteilungen', 'redakteur', 'Redaktion ausdrücklich übertragen', $expires);
    $this->noticeA = Announcement::create(['title' => 'Nord', 'created_by' => $this->editor->id, 'ext_location_id' => $this->a->id]);
    $this->noticeB = Announcement::create(['title' => 'Süd', 'created_by' => $this->editor->id, 'ext_location_id' => $this->b->id]);
    $this->general = Announcement::create(['title' => 'Allgemein', 'created_by' => $this->editor->id]);
    Http::preventStrayRequests();
});

it('uses explicit independent editorial grants and never legacy roles authorship or location administration', function () {
    expect($this->service->canManageAnything($this->editor))->toBeFalse()
        ->and($this->service->canManage($this->editor, $this->noticeA))->toBeFalse()
        ->and($this->service->canManage($this->editor, $this->noticeB))->toBeFalse()
        ->and($this->service->canManage($this->editor, $this->general))->toBeFalse();
    $grantA = ($this->grant)();
    expect($this->editor->canPostAnnouncements())->toBeTrue()
        ->and($this->editor->hasModuleRole('mitteilungen', 'redakteur'))->toBeFalse()
        ->and($this->service->managementQuery($this->editor)->get()->modelKeys())->toBe([$this->noticeA->id]);
    $grantB = ($this->grant)($this->b);
    $this->roles->revoke($this->admin, $grantA->id, $this->a->id, 'Nord beendet');
    expect($this->service->canManage($this->editor, $this->noticeA))->toBeFalse()
        ->and($this->service->canManage($this->editor, $this->noticeB))->toBeTrue()
        ->and($this->service->manageableLocationIds($this->editor))->toBe([$this->b->id])
        ->and($grantB->fresh()->revoked_at)->toBeNull();
    expect(fn () => DB::transaction(fn () => DB::table('x_location_module_roles')->where('id', $grantA->id)->delete()))
        ->toThrow(QueryException::class);
});

it('only permits fresh global administrators to grant and retains existing immutable protections', function () {
    expect(fn () => ($this->grant)())->not->toThrow(Throwable::class);
    $grant = LocationModuleRole::where('module', 'mitteilungen')->firstOrFail();
    expect(fn () => ($this->grant)())->toThrow(ValidationException::class)
        ->and(fn () => $this->roles->revoke($this->editor, $grant->id, $this->a->id, 'Nicht erlaubt'))->toThrow(HttpException::class)
        ->and(fn () => $this->roles->grant($this->editor, $this->b->id, $this->editor->id, 'mitteilungen', 'redakteur', 'Nicht erlaubt'))->toThrow(HttpException::class);
    DB::table('users')->where('id', $this->admin->id)->update(['is_admin' => false]);
    expect(fn () => $this->roles->revoke($this->admin, $grant->id, $this->a->id, 'Veraltetes Modell'))->toThrow(HttpException::class);
});

it('rechecks live expiry membership activity and stale admin models', function (string $loss) {
    $grant = ($this->grant)($this->a, now()->addMinute()->toIso8601String());
    expect($this->service->canManage($this->editor, $this->noticeA))->toBeTrue();
    match ($loss) {
        'expiry' => $this->travel(2)->minutes(),
        'member' => DB::table('users')->where('id', $this->editor->id)->update(['is_active' => false]),
        'location' => DB::table('x_locations')->where('id', $this->a->id)->update(['is_active' => false]),
        'module' => DB::table('modules')->where('key', 'mitteilungen')->update(['is_active' => false]),
        'membership' => DB::table('users')->where('id', $this->editor->id)->update(['ext_location_id' => null]),
        'revoke' => $this->roles->revoke($this->admin, $grant->id, $this->a->id, 'Redaktion beendet'),
    };
    expect($this->service->canManage($this->editor, $this->noticeA))->toBeFalse()
        ->and($this->editor->hasModuleRole('mitteilungen', 'redakteur', $this->a->id))->toBeFalse();
    expect(fn () => $this->service->save($this->noticeA, ['title' => 'Nicht ändern'], [], $this->editor, locationId: $this->a->id))
        ->toThrow(HttpException::class);
})->with(['expiry', 'member', 'location', 'module', 'membership', 'revoke']);

it('does not revive editorial rights after membership is restored', function () {
    $grant = ($this->grant)($this->b);
    DB::table('x_location_user')->where('user_id', $this->editor->id)->where('location_id', $this->b->id)->delete();
    DB::table('x_location_user')->insert(['location_id' => $this->b->id, 'user_id' => $this->editor->id, 'role' => 'admin']);
    expect($grant->fresh()->revoked_source)->toBe('membership')
        ->and($this->service->canManage($this->editor, $this->noticeB))->toBeFalse();
});

it('checks both current and proposed targets and refuses forged stale announcement scope', function () {
    ($this->grant)();
    expect(fn () => $this->service->save($this->noticeA, ['title' => 'Allgemein'], [], $this->editor))->toThrow(HttpException::class)
        ->and(fn () => $this->service->save($this->noticeB, ['title' => 'Übernehmen'], [], $this->editor, locationId: $this->a->id))->toThrow(HttpException::class);
    DB::table('announcements')->where('id', $this->noticeA->id)->update(['ext_location_id' => $this->b->id]);
    expect($this->service->canManage($this->editor, $this->noticeA))->toBeFalse()
        ->and(fn () => $this->service->save($this->noticeA, ['title' => 'Veraltet'], [], $this->editor, locationId: $this->a->id))->toThrow(HttpException::class);
});

it('binds group and function audience ids to the exact authorized target', function (string $kind) {
    ($this->grant)();
    ($this->grant)($this->b);
    $audience = $kind === 'group' ? Group::create(['name' => 'Südgruppe']) : MemberFunction::create(['name' => 'Südfunktion']);
    $audience->forceFill(['ext_location_id' => $this->b->id])->save();
    expect(fn () => $this->service->save(null, ['title' => 'Falsch'], $kind === 'group' ? [$audience->id] : [], $this->editor,
        functionIds: $kind === 'function' ? [$audience->id] : [], locationId: $this->a->id))->toThrow(HttpException::class);
    $kind === 'group' ? $this->noticeA->groups()->sync([$audience->id]) : $this->noticeA->functions()->sync([$audience->id]);
    expect($this->service->canManage($this->editor, $this->noticeA))->toBeFalse()
        ->and($this->service->managementQuery($this->editor)->whereKey($this->noticeA->id)->exists())->toBeFalse();
})->with(['group', 'function']);

it('keeps membership and audience reader entitlement distinct from editorial grants', function () {
    ($this->grant)();
    $group = Group::create(['name' => 'Nur Empfänger', 'ext_location_id' => $this->a->id]);
    $group->forceFill(['ext_location_id' => $this->a->id])->save();
    $this->noticeA->groups()->sync([$group->id]);
    $recipient = User::factory()->create(['ext_location_id' => $this->a->id]);
    $recipient->moduleAccess()->create(['module' => 'mitteilungen']);
    $recipient->groups()->attach($group);
    expect($this->service->canManage($this->editor, $this->noticeA))->toBeTrue()
        ->and(Announcement::for($this->editor)->whereKey($this->noticeA->id)->exists())->toBeFalse()
        ->and($this->service->recipients($this->noticeA)->modelKeys())->toBe([$recipient->id])
        ->and($this->service->canManage($recipient, $this->noticeA))->toBeFalse();
    $this->actingAs($recipient)->get(route('mitteilungen.manage'))->assertForbidden();
});

it('permits explicit no-login background actors but refuses missing actors for sensitive services', function () {
    ($this->grant)();
    DB::table('users')->where('id', $this->editor->id)->update(['email' => null]);
    Notification::fake();
    auth()->forgetGuards();
    expect($this->service->stats($this->noticeA, $this->editor)['recipients'])->toBeGreaterThan(0)
        ->and($this->service->mail($this->noticeA, by: $this->editor))->toBeGreaterThan(0);
    expect(fn () => $this->service->stats($this->noticeA))->toThrow(HttpException::class)
        ->and(fn () => $this->service->mail($this->noticeA))->toThrow(HttpException::class)
        ->and(fn () => $this->service->remindUnacknowledged($this->noticeA))->toThrow(HttpException::class)
        ->and(fn () => app(AnnouncementDelivery::class)->send($this->noticeA, ['ntfy' => true, 'matrix' => true]))->toThrow(HttpException::class);
});

it('requires fresh global admins for general management and all scoped management', function (string $loss) {
    expect($this->service->canManage($this->admin, $this->general))->toBeTrue()
        ->and($this->service->canManage($this->admin, $this->noticeB))->toBeTrue();
    $this->service->save($this->general, ['title' => 'Allgemein geändert'], [], $this->admin);
    DB::table('users')->where('id', $this->admin->id)->update([$loss => false]);
    expect($this->service->canManageAnything($this->admin))->toBeFalse()
        ->and($this->service->canManage($this->admin, $this->general))->toBeFalse()
        ->and($this->service->managementQuery($this->admin)->exists())->toBeFalse();
})->with(['is_admin', 'is_active']);

it('reauthorizes mounted Livewire actions after an independent target grant is revoked', function (string $action) {
    $grantA = ($this->grant)();
    ($this->grant)($this->b);
    $file = $this->noticeA->files()->create(['filename' => 'intern.txt', 'mime' => 'text/plain', 'size' => 4, 'data' => base64_encode('Test')]);
    $component = Livewire::actingAs($this->editor)->test(Manage::class)
        ->assertSet('locationId', (string) $this->a->id)
        ->call('edit', $this->noticeA->id);
    $this->roles->revoke($this->admin, $grantA->id, $this->a->id, 'Nord beendet');
    $argument = $action === 'deleteFile' ? $file->id : $this->noticeA->id;
    $action === 'save'
        ? $component->call('save')->assertForbidden()
        : $component->call($action, $argument)->assertForbidden();
    expect(Announcement::whereKey($this->noticeA->id)->exists())->toBeTrue()
        ->and(AnnouncementFile::whereKey($file->id)->exists())->toBeTrue();
})->with(['edit', 'save', 'delete', 'deleteFile', 'showStats', 'remind']);

it('filters mount scope choices and denies tampered scope selection and unauthorized direct mount', function () {
    $this->actingAs($this->editor)->get(route('mitteilungen.manage'))->assertForbidden();
    Livewire::actingAs($this->editor)->test(Manage::class)->assertForbidden();
    ($this->grant)();
    Livewire::actingAs($this->editor)->test(Manage::class)
        ->assertSee('Wache Nord')->assertDontSee('Wache Süd')->assertDontSee('Allgemein – standortübergreifend')
        ->set('locationId', (string) $this->b->id)->assertForbidden();
});

it('serves files only through current editor authority or unchanged reader entitlement', function () {
    $grant = ($this->grant)();
    $group = Group::create(['name' => 'Nicht die Redaktion', 'ext_location_id' => $this->a->id]);
    $group->forceFill(['ext_location_id' => $this->a->id])->save();
    $this->noticeA->groups()->sync([$group->id]);
    $file = $this->noticeA->files()->create(['filename' => 'intern.txt', 'mime' => 'text/plain', 'size' => 4, 'data' => base64_encode('Test')]);
    $this->actingAs($this->editor)->get(route('mitteilungen.file', $file))->assertOk();
    $this->roles->revoke($this->admin, $grant->id, $this->a->id, 'Redaktion beendet');
    $this->get(route('mitteilungen.file', $file))->assertForbidden();
    $this->editor->groups()->attach($group);
    $this->get(route('mitteilungen.file', $file))->assertOk();
});

it('separates channel configuration from current target send authority including direct calls', function () {
    $grant = ($this->grant)($this->b);
    app(ChannelSettings::class)->save($this->editor, 'location:'.$this->b->id, 'ntfy', [
        'enabled' => true, 'server_url' => 'https://notify.example.com', 'target' => 'south', 'token' => 'invented-test-token',
    ]);
    Http::fake(['https://notify.example.com/*' => Http::response('', 200)]);
    app(AnnouncementDelivery::class)->send($this->noticeB, ['ntfy' => true, 'matrix' => false], $this->editor);
    Http::assertSentCount(1);
    $this->roles->revoke($this->admin, $grant->id, $this->b->id, 'Redaktion beendet');
    app(ChannelSettings::class)->assertMayManage($this->editor, 'location:'.$this->b->id);
    expect(fn () => app(AnnouncementDelivery::class)->send($this->noticeB, ['ntfy' => true, 'matrix' => false], $this->editor))->toThrow(HttpException::class)
        ->and(fn () => app(AnnouncementDelivery::class)->send($this->general, ['ntfy' => true, 'matrix' => false], $this->editor))->toThrow(HttpException::class)
        ->and(fn () => $this->service->stats($this->noticeB, $this->editor))->toThrow(HttpException::class)
        ->and(fn () => $this->service->remindUnacknowledged($this->noticeB, $this->editor))->toThrow(HttpException::class);
    Http::assertSentCount(1);
    expect(ChannelDelivery::count())->toBe(1);
});

it('preserves legacy default-off behavior without distributing or activating grants', function () {
    config(['locations.scoped_role_modules' => []]);
    app()->singleton(AnnouncementService::class, AnnouncementService::class);
    $legacy = app(AnnouncementService::class);
    expect($legacy->canManage($this->editor, $this->noticeB))->toBeTrue()
        ->and(LocationModuleRole::count())->toBe(0);
    ($this->grant)();
    expect(LocationModuleRole::count())->toBe(1)
        ->and($legacy->canManage($this->editor, $this->noticeB))->toBeTrue();
});

it('does not swallow current send authorization failure after a previously authorized save', function () {
    $grant = ($this->grant)();
    Event::listen('eloquent.saved: '.Announcement::class, function (Announcement $announcement) use ($grant) {
        if ($announcement->title === 'Entzug beim Speichern') {
            $this->roles->revoke($this->admin, $grant->id, $this->a->id, 'Redaktion während der Aktion beendet');
        }
    });
    expect(fn () => $this->service->save($this->noticeA, ['title' => 'Entzug beim Speichern'], [], $this->editor,
        ntfy: true, matrix: true, locationId: $this->a->id))->toThrow(HttpException::class);
    expect($this->noticeA->fresh()->title)->toBe('Entzug beim Speichern')
        ->and($this->service->externalStatus)->toBe([])
        ->and(ChannelDelivery::count())->toBe(0);
    Http::assertNothingSent();
});

it('never promotes an editorial grant into external channel configuration rights', function () {
    ($this->grant)();
    expect(fn () => app(ChannelSettings::class)->save($this->editor, 'location:'.$this->a->id, 'ntfy', [
        'enabled' => true, 'server_url' => 'https://notify.example.com', 'target' => 'north', 'token' => 'invented-test-token',
    ]))->toThrow(HttpException::class);
});

it('uses fresh primary and pivot membership for readers without turning global admins into recipients', function (bool $clearPrimary) {
    ($this->grant)();
    DB::table('users')->where('id', $this->editor->id)->update(['ext_location_id' => $clearPrimary ? null : $this->b->id]);
    expect($this->editor->getAttribute('ext_location_id'))->toBe($this->a->id)
        ->and(Announcement::for($this->editor)->whereKey($this->noticeA->id)->exists())->toBeFalse()
        ->and(Announcement::for($this->editor)->whereKey($this->noticeB->id)->exists())->toBeTrue()
        ->and(Announcement::for($this->editor)->whereKey($this->general->id)->exists())->toBeTrue()
        ->and($this->service->recipients($this->noticeA)->modelKeys())->not->toContain($this->editor->id, $this->admin->id)
        ->and($this->service->canManage($this->admin, $this->noticeA))->toBeTrue();
    DB::table('x_location_user')->where('user_id', $this->editor->id)->where('location_id', $this->b->id)->delete();
    if ($clearPrimary) {
        expect(Announcement::for($this->editor)->whereKey($this->noticeB->id)->exists())->toBeFalse();
    }
    $file = $this->noticeA->files()->create(['filename' => 'intern.txt', 'mime' => 'text/plain', 'size' => 4, 'data' => base64_encode('Test')]);
    $this->actingAs($this->editor)->get(route('mitteilungen.file', $file))->assertForbidden();
})->with([false, true]);

it('retains default-off loaded primary reader membership behavior', function () {
    config(['locations.scoped_role_modules' => []]);
    app()->singleton(AnnouncementService::class, AnnouncementService::class);
    DB::table('users')->where('id', $this->editor->id)->update(['ext_location_id' => null]);
    expect(Announcement::for($this->editor)->whereKey($this->noticeA->id)->exists())->toBeTrue();
});

it('rechecks current send authority between selected external transports', function () {
    $grant = ($this->grant)();
    foreach (['ntfy' => 'north', 'matrix' => '!north:example.com'] as $transport => $target) {
        app(ChannelSettings::class)->save($this->admin, 'location:'.$this->a->id, $transport, [
            'enabled' => true, 'server_url' => 'https://notify.example.com', 'target' => $target, 'token' => 'invented-test-token',
        ]);
    }
    Http::fake(function () use ($grant) {
        $this->roles->revoke($this->admin, $grant->id, $this->a->id, 'Nach erstem Kanal beendet');

        return Http::response('', 200);
    });
    expect(fn () => app(AnnouncementDelivery::class)->send($this->noticeA, ['ntfy' => true, 'matrix' => true], $this->editor))
        ->toThrow(HttpException::class);
    Http::assertSentCount(1);
    expect(ChannelDelivery::count())->toBe(1);
});

it('validates dependencies per selected module and fails closed for unsupported modules', function () {
    config(['anwesenheit.enabled' => false]);
    expect($this->roles->configuredModules())->toBe(['mitteilungen']);
    config(['locations.scoped_role_modules' => ['anwesenheit', 'mitteilungen']]);
    expect(fn () => $this->roles->configuredModules())->toThrow(InvalidArgumentException::class);
    config(['anwesenheit.enabled' => true]);
    expect($this->roles->configuredModules())->toBe(['anwesenheit', 'mitteilungen']);
    config(['locations.scoped_role_modules' => ['mitteilungen', 'kalender']]);
    expect(fn () => $this->roles->configuredModules())->toThrow(InvalidArgumentException::class);
    config(['locations.scoped_role_modules' => ['mitteilungen'], 'locations.enabled' => false]);
    expect(fn () => $this->roles->configuredModules())->toThrow(InvalidArgumentException::class);
});

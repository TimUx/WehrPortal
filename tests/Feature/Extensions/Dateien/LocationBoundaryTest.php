<?php

use App\Models\Module;
use App\Models\User;
use Extensions\Dateien\Livewire\Explorer;
use Extensions\Dateien\Models\FileGroup;
use Extensions\Dateien\Models\Permission;
use Extensions\Dateien\Models\Space;
use Extensions\Dateien\Notifications\FileActivityNotification;
use Extensions\Dateien\Notifications\SharedWithYouNotification;
use Extensions\Dateien\Services\CollaborationService;
use Extensions\Dateien\Services\FileAccess;
use Extensions\Dateien\Services\FileService;
use Extensions\Dateien\Services\ShareService;
use Extensions\Locations\Models\Location;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    config(['locations.enabled' => true]);
    Module::where('key', 'dateien')->update(['is_active' => true, 'auto_assign' => false]);
    Cache::flush();
    Notification::fake();
    Storage::fake('dateien');
    $this->north = Location::default();
    $this->south = Location::create(['name' => 'Wache Süd', 'slug' => 'dateien-boundary-sued']);
    $this->admin = filesMember('Ada Atemschutz', $this->north, true);
    $this->member = filesMember('Hanna Hydrant', $this->north);
    $this->files = app(FileService::class);
    $this->access = app(FileAccess::class);
    $this->collab = app(CollaborationService::class);
    $this->spaces = [];
    $this->folders = [];
    $this->nodes = [];
    DB::table('x_location_user')->insert(['location_id' => $this->south->id, 'user_id' => $this->member->id, 'role' => 'member']);
    foreach (['north' => $this->north, 'south' => $this->south] as $key => $location) {
        $space = $this->files->createSpace($this->admin, ['name' => 'Unterlagen '.$key, 'ext_location_id' => $location->id]);
        $folder = $this->files->createFolder($this->admin, $space, null, 'Dienst '.$key);
        $node = $this->files->upload($this->admin, $space, $folder, filesUpload('Inhalt '.$key, 'dienst-'.$key.'.txt'));
        $this->files->setPermission($this->admin, $space, 'user', $this->member->id, 'manage');
        $this->files->setPermission($this->admin, $node, 'user', $this->member->id, 'manage');
        $this->spaces[$key] = $space;
        $this->folders[$key] = $folder;
        $this->nodes[$key] = $node;
    }
    DB::table('x_location_user')->where('user_id', $this->member->id)->where('location_id', $this->south->id)->delete();
});

test('internal downloads bind versions to their parent and independently require the target Standort', function () {
    $north = $this->nodes['north'];
    $south = $this->nodes['south'];
    $northVersion = $north->versions()->sole();
    $southVersion = $south->versions()->sole();
    $this->actingAs($this->member)
        ->get(route('dateien.download', ['node' => $north->id, 'version' => $northVersion->id]))
        ->assertOk()->assertStreamedContent('Inhalt north');
    $this->get(route('dateien.download', ['node' => $north->id, 'version' => $southVersion->id]))->assertNotFound();
    $this->get(route('dateien.download', ['node' => $south->id, 'version' => $southVersion->id]))->assertForbidden();

    DB::table('x_location_user')->insert(['location_id' => $this->south->id, 'user_id' => $this->member->id, 'role' => 'member']);
    $this->get(route('dateien.download', ['node' => $south->id, 'version' => $southVersion->id]))
        ->assertOk()->assertStreamedContent('Inhalt south');
    $this->get(route('dateien.download', ['node' => $south->id, 'version' => $northVersion->id]))->assertNotFound();
    Permission::where('subject_type', 'user')->where('subject_id', $this->member->id)->delete();
    $this->get(route('dateien.download', ['node' => $south->id, 'version' => $southVersion->id]))->assertForbidden();
    $this->actingAs($this->admin)->get(route('dateien.download', ['node' => $south->id, 'version' => $southVersion->id]))
        ->assertOk()->assertStreamedContent('Inhalt south');
});

test('either surviving primary or pivot membership retains the target Standort access', function () {
    $node = $this->nodes['north'];
    $url = route('dateien.download', ['node' => $node->id, 'version' => $node->versions()->sole()->id]);
    DB::table('x_location_user')->where('user_id', $this->member->id)->where('location_id', $this->north->id)->delete();
    expect($this->access->can($this->member, $node, 'read'))->toBeTrue();
    $this->actingAs($this->member)->get($url)->assertOk()->assertStreamedContent('Inhalt north');

    DB::table('x_location_user')->insert(['location_id' => $this->north->id, 'user_id' => $this->member->id, 'role' => 'member']);
    User::withoutGlobalScopes()->whereKey($this->member->id)->update(['ext_location_id' => $this->south->id]);
    expect($this->access->can($this->member, $node, 'read'))->toBeTrue();
    $this->get($url)->assertOk()->assertStreamedContent('Inhalt north');

    DB::table('x_location_user')->where('user_id', $this->member->id)->where('location_id', $this->north->id)->delete();
    expect($this->access->can($this->member, $node, 'read'))->toBeFalse();
    $this->get($url)->assertForbidden();
});

test('cached image previews still enforce live target membership and ACL', function () {
    $image = imagecreatetruecolor(16, 16);
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();
    imagedestroy($image);
    foreach ($this->spaces as $key => $space) {
        $photos[$key] = $this->files->upload($this->admin, $space, null, UploadedFile::fake()->createWithContent('bild.png', $png));
        $this->actingAs($this->admin)->get(route('dateien.preview', ['node' => $photos[$key]->id]))->assertOk();
    }
    expect(DB::table('x_files_previews')->count())->toBe(2);
    $this->actingAs($this->member)->get(route('dateien.preview', ['node' => $photos['north']->id]))->assertOk();
    $this->get(route('dateien.preview', ['node' => $photos['south']->id]))->assertForbidden();
    DB::table('x_location_user')->insert(['location_id' => $this->south->id, 'user_id' => $this->member->id, 'role' => 'member']);
    $this->get(route('dateien.preview', ['node' => $photos['south']->id]))->assertOk();
    Permission::where('target_type', 'space')->where('target_id', $this->spaces['south']->id)->delete();
    $this->get(route('dateien.preview', ['node' => $photos['south']->id]))->assertForbidden();
    expect(DB::table('x_files_previews')->count())->toBe(2);
});

test('folder ZIPs require the target Standort and filter recursive child ACLs', function () {
    $space = $this->spaces['north'];
    $folder = $this->folders['north'];
    $allowed = $this->files->createFolder($this->admin, $space, $folder, 'Lesbar');
    $this->files->upload($this->admin, $space, $allowed, filesUpload('Erlaubte Bytes', 'erlaubt.txt'));
    $hidden = $this->files->createFolder($this->admin, $space, $allowed, 'Gesperrt');
    $this->files->setInheritance($this->admin, $hidden, false);
    $direct = $this->files->upload($this->admin, $space, $hidden, filesUpload('Versteckte Bytes', 'direkt.txt'));
    $this->files->setPermission($this->admin, $direct, 'user', $this->member->id, 'read');
    $blockedFile = $this->files->upload($this->admin, $space, $allowed, filesUpload('Gesperrte Bytes', 'gesperrt.txt'));
    $this->files->setInheritance($this->admin, $blockedFile, false);
    $response = $this->actingAs($this->member)->get(route('dateien.zip', ['node' => $folder->id]))->assertOk();
    expect($response->streamedContent())->toContain('Lesbar/erlaubt.txt', 'Erlaubte Bytes', 'Inhalt north')
        ->not->toContain('Gesperrt', 'Versteckte Bytes', 'Gesperrte Bytes', 'gesperrt.txt');
    $this->get(route('dateien.zip', ['node' => $this->folders['south']->id]))->assertForbidden();
    DB::table('x_location_user')->insert(['location_id' => $this->south->id, 'user_id' => $this->member->id, 'role' => 'member']);
    $response = $this->get(route('dateien.zip', ['node' => $this->folders['south']->id]))->assertOk();
    expect($response->streamedContent())->toContain('Inhalt south');
    DB::table('x_location_user')->where('user_id', $this->member->id)->where('location_id', $this->south->id)->delete();
    $this->get(route('dateien.zip', ['node' => $this->folders['south']->id]))->assertForbidden();
});

test('search shared favorites and recent discard targets after independent location and ACL revocation', function () {
    DB::table('x_location_user')->insert(['location_id' => $this->south->id, 'user_id' => $this->member->id, 'role' => 'member']);
    foreach ($this->nodes as $node) {
        $this->collab->toggleFavorite($this->member, $node);
        $this->actingAs($this->member)->get(route('dateien.download', ['node' => $node->id, 'version' => $node->versions()->sole()->id]))->assertOk();
    }
    $lists = fn () => [
        $this->files->search($this->member, '.txt'),
        $this->files->shared($this->member),
        $this->collab->favorites($this->member),
        $this->collab->recent($this->member),
    ];
    foreach ($lists() as $rows) {
        expect($rows->pluck('id')->all())->toEqualCanonicalizing(array_map(fn ($node) => $node->id, $this->nodes));
    }
    DB::table('x_location_user')->where('user_id', $this->member->id)->where('location_id', $this->south->id)->delete();
    foreach ($lists() as $rows) {
        expect($rows->pluck('id')->all())->toBe([$this->nodes['north']->id]);
    }
    Permission::where('subject_type', 'user')->where('subject_id', $this->member->id)->delete();
    foreach ($lists() as $rows) {
        expect($rows)->toBeEmpty();
    }
});

test('mounted Explorer actions reauthorize the independently granted target', function (string $revocation, string $action) {
    DB::table('x_location_user')->insert(['location_id' => $this->south->id, 'user_id' => $this->member->id, 'role' => 'member']);
    $component = Livewire::actingAs($this->member)->test(Explorer::class)
        ->call('navigate', $this->spaces['south']->id)
        ->call('select', $this->nodes['south']->id)
        ->set('name', 'umbenannt.txt')->set('folderName', 'Neuer Ordner');
    match ($revocation) {
        'location' => DB::table('x_location_user')->where('user_id', $this->member->id)->where('location_id', $this->south->id)->delete(),
        'acl' => Permission::where('subject_type', 'user')->where('subject_id', $this->member->id)->delete(),
        'module' => $this->member->moduleAccess()->where('module', 'dateien')->delete(),
    };
    $component->call($action)->assertForbidden();
    expect($this->nodes['south']->fresh()->name)->toBe('dienst-south.txt')
        ->and($this->nodes['south']->fresh()->trashed())->toBeFalse()
        ->and($this->spaces['south']->nodes()->where('name', 'Neuer Ordner')->exists())->toBeFalse();
})->with(['location', 'acl', 'module'])->with(['rename', 'trash', 'createFolder']);

test('stale actor flags primary location and space ownership cannot retain data rights', function (string $change) {
    $actor = $this->member;
    if ($change === 'admin') {
        $actor = $this->admin;
    }
    expect($this->access->can($actor, $this->nodes['north'], 'manage'))->toBeTrue();
    match ($change) {
        'admin' => User::withoutGlobalScopes()->whereKey($actor->id)->update(['is_admin' => false]),
        'inactive' => $actor->fresh()->update(['is_active' => false]),
        'primary' => (function () use ($actor) {
            User::withoutGlobalScopes()->whereKey($actor->id)->update(['ext_location_id' => $this->south->id]);
            DB::table('x_location_user')->where('user_id', $actor->id)->delete();
        })(),
        'space' => Space::whereKey($this->spaces['north']->id)->update(['ext_location_id' => $this->south->id]),
    };
    expect($this->access->can($actor, $this->nodes['north'], 'read'))->toBeFalse()
        ->and($this->access->can($actor, $this->spaces['north'], 'read'))->toBeFalse();
    expect(fn () => $this->files->rename($actor, $this->nodes['north'], 'nicht-erlaubt.txt'))->toThrow(AuthorizationException::class);
    expect($this->nodes['north']->fresh()->name)->toBe('dienst-north.txt');
})->with(['admin', 'inactive', 'primary', 'space']);

test('a no-location actor has only explicit shared NULL rights and module roles are not file ACLs', function () {
    $actor = User::factory()->create(['name' => 'Bernd Blaulicht', 'email' => 'bernd-dateien@example.com', 'ext_location_id' => null]);
    // Model creation assigns a default Standort; represent an unassigned historical row explicitly.
    DB::table('users')->where('id', $actor->id)->update(['ext_location_id' => null]);
    $actor->moduleAccess()->create(['module' => 'dateien', 'role' => 'gruppenverwalter']);
    $space = $this->files->createSpace($this->admin, ['name' => 'Gemeinsam']);
    $group = FileGroup::create(['name' => 'Gemeinsame Gruppe', 'ext_location_id' => null]);
    $group->members()->attach($actor->id, ['is_manager' => true]);
    expect($this->access->can($actor, $space, 'read'))->toBeFalse()
        ->and($this->access->can($actor, $this->nodes['north'], 'read'))->toBeFalse();
    $this->files->setPermission($this->admin, $space, 'group', $group->id, 'read');
    expect($this->access->can($actor, $space, 'read'))->toBeTrue()
        ->and($this->access->can($actor, $space, 'edit'))->toBeFalse();
    Permission::create(['target_type' => 'node', 'target_id' => $this->nodes['north']->id, 'subject_type' => 'group', 'subject_id' => $group->id, 'level' => 'manage']);
    expect($this->access->can($actor, $this->nodes['north'], 'read'))->toBeFalse();
    $group->members()->detach($actor->id);
    expect($this->access->can($actor, $space, 'read'))->toBeFalse();
});

test('public shares preserve guest bearer access while managers and child IDs remain target bound', function () {
    $shares = app(ShareService::class);
    $shares->saveSettings($this->admin, true, 30);
    foreach ($this->spaces as $space) {
        $shares->allowSpace($this->admin, $space, true);
    }
    expect(fn () => $shares->create($this->member, $this->folders['south'], 7))->toThrow(AuthorizationException::class);
    [$share, $token] = $shares->create($this->member, $this->folders['north'], 7);
    $this->get(route('dateien.share', ['token' => $token]))->assertOk()->assertSee('dienst-north.txt')->assertDontSee('dienst-south.txt');
    $this->get(route('dateien.share.download', ['token' => $token, 'node' => $this->nodes['north']->id]))
        ->assertOk()->assertStreamedContent('Inhalt north');
    $this->get(route('dateien.share.download', ['token' => $token, 'node' => $this->nodes['south']->id]))->assertNotFound();
    expect(auth()->check())->toBeFalse();
    $shares->revoke($this->member, $share);
    $this->get(route('dateien.share', ['token' => $token]))->assertNotFound();

    DB::table('x_location_user')->insert(['location_id' => $this->south->id, 'user_id' => $this->member->id, 'role' => 'member']);
    [$southShare, $southToken] = $shares->create($this->member, $this->folders['south'], 7);
    $this->get(route('dateien.share', ['token' => $southToken]))->assertOk();
    DB::table('x_location_user')->where('user_id', $this->member->id)->where('location_id', $this->south->id)->delete();
    expect(fn () => $shares->revoke($this->member, $southShare))->toThrow(AuthorizationException::class);
    $this->get(route('dateien.share', ['token' => $southToken]))->assertNotFound();
    $shares->revoke($this->admin, $southShare);
    expect($southShare->fresh()->revoked_by)->toBe($this->admin->id);
});

test('shared NULL subscriptions deliver across Standorte independently of the requesting actor', function (bool $authenticated) {
    $recipient = filesMember('Bernd Blaulicht', $this->south);
    $space = $this->files->createSpace($this->admin, ['name' => 'Gemeinsame Ausbildung']);
    $node = $this->files->upload($this->admin, $space, null, filesUpload());
    $this->files->setPermission($this->admin, $node, 'user', $this->member->id, 'edit');
    $this->files->setPermission($this->admin, $node, 'user', $recipient->id, 'read');
    $this->collab->toggleSubscription($recipient, $node);
    if ($authenticated) {
        $this->actingAs($this->member);
    }
    $this->collab->comment($this->member, $node, 'Bitte gemeinsam prüfen.');
    Notification::assertSentTo($recipient, FileActivityNotification::class);
    Notification::assertNotSentTo($this->member, FileActivityNotification::class);
})->with(['authenticated North actor' => true, 'without request authentication' => false]);

test('deferred shared NULL notifications reauthorize recipients before delivery without request authentication', function (string $revocation) {
    $recipient = filesMember('Bernd Blaulicht', $this->south);
    $space = $this->files->createSpace($this->admin, ['name' => 'Gemeinsame Ausbildung']);
    $node = $this->files->upload($this->admin, $space, null, filesUpload());
    $this->files->setPermission($this->admin, $node, 'user', $recipient->id, 'read');
    $this->collab->toggleSubscription($recipient, $node);

    Notification::fake();
    DB::beginTransaction();
    $this->collab->notifySubscribers($this->member, $node, 'version');
    Notification::assertNothingSent();
    match ($revocation) {
        'acl' => Permission::where('subject_type', 'user')->where('subject_id', $recipient->id)->delete(),
        'module' => $recipient->moduleAccess()->where('module', 'dateien')->delete(),
        'inactive' => $recipient->fresh()->update(['is_active' => false]),
        'none' => null,
    };
    DB::commit();

    expect(auth()->check())->toBeFalse();
    if ($revocation === 'none') {
        Notification::assertSentTo($recipient, FileActivityNotification::class);
    } else {
        Notification::assertNothingSent();
    }
})->with(['acl', 'module', 'inactive', 'none']);

test('authenticated North managers notify eligible South recipients of shared NULL ACL grants', function (string $subjectType) {
    $recipient = filesMember('Bernd Blaulicht', $this->south);
    $space = $this->files->createSpace($this->admin, ['name' => 'Gemeinsame Ausbildung']);
    $node = $this->files->upload($this->admin, $space, null, filesUpload());
    $this->files->setPermission($this->admin, $node, 'user', $this->member->id, 'manage');
    $group = FileGroup::create(['name' => 'Gemeinsame Ausbildung', 'ext_location_id' => null]);
    $group->members()->attach($recipient->id, ['is_manager' => false]);
    Notification::fake();

    $this->actingAs($this->member);
    $this->files->setPermission($this->member, $node, $subjectType, $subjectType === 'user' ? $recipient->id : $group->id, 'read');

    Notification::assertSentTo($recipient, SharedWithYouNotification::class);
    Notification::assertNotSentTo($this->member, SharedWithYouNotification::class);
    expect($this->access->can($recipient, $node, 'read'))->toBeTrue();
})->with(['user', 'group']);

test('deferred shared NULL group grant notifications check current recipient eligibility without request authentication', function () {
    $eligible = filesMember('Bernd Blaulicht', $this->south);
    $inactive = filesMember('Clara C-Schlauch', $this->south);
    $moduleRevoked = filesMember('Dora Drehleiter', $this->south);
    $groupRevoked = filesMember('Emil Einsatz', $this->south);
    $space = $this->files->createSpace($this->admin, ['name' => 'Gemeinsame Ausbildung']);
    $group = FileGroup::create(['name' => 'Gemeinsame Ausbildung', 'ext_location_id' => null]);
    $group->members()->attach([$eligible->id, $inactive->id, $moduleRevoked->id, $groupRevoked->id]);
    Notification::fake();

    DB::beginTransaction();
    $this->files->setPermission($this->admin, $space, 'group', $group->id, 'read');
    Notification::assertNothingSent();
    $inactive->fresh()->update(['is_active' => false]);
    $moduleRevoked->moduleAccess()->where('module', 'dateien')->delete();
    $group->members()->detach($groupRevoked->id);
    DB::commit();

    Notification::assertSentTo($eligible, SharedWithYouNotification::class);
    foreach ([$inactive, $moduleRevoked, $groupRevoked] as $revoked) {
        Notification::assertNotSentTo($revoked, SharedWithYouNotification::class);
    }
    expect(auth()->check())->toBeFalse();
});

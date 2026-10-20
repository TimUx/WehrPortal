<?php

use App\Models\Group;
use App\Models\MemberFunction;
use App\Models\User;
use App\Support\SafeUrl;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\LocationAccess;
use Extensions\Mitteilungen\Models\ChannelDelivery;
use Extensions\Mitteilungen\Models\ChannelDestination;
use Extensions\Mitteilungen\Services\ChannelSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Validation\ValidationException;
use Modules\Mitteilungen\src\Models\Announcement;
use Modules\Mitteilungen\src\Models\AnnouncementFile;
use Modules\Mitteilungen\src\Services\AnnouncementService;

function channelLocation(string $name): Location
{
    return Location::create(['name' => $name, 'slug' => str($name)->slug()->toString()]);
}

function channelMember(Location $location, bool $module = true, bool $admin = false, ?int $primary = null): User
{
    $user = User::factory()->create(['is_admin' => $admin]);
    $user->forceFill(['ext_location_id' => $primary ?? $location->id])->save();
    DB::table('x_location_user')->insert(['location_id' => $location->id, 'user_id' => $user->id, 'role' => 'member']);
    if ($module) {
        $user->moduleAccess()->create(['module' => 'mitteilungen']);
    }
    app(LocationAccess::class)->forget($user);

    return $user;
}

beforeEach(function () {
    Http::preventStrayRequests();
    SafeUrl::resolveUsing(fn () => ['93.184.216.34']);
});

afterEach(function () {
    SafeUrl::resolveUsing(null);
});

it('limits location notices to eligible members while general notices cross locations', function () {
    $north = Location::default();
    $south = channelLocation('Wache Sued');
    $northMember = channelMember($north);
    $southMember = channelMember($south);
    $multi = channelMember($north, primary: (int) $north->id);
    DB::table('x_location_user')->insert(['location_id' => $south->id, 'user_id' => $multi->id, 'role' => 'member']);
    app(LocationAccess::class)->forget($multi);
    $withoutLocation = User::factory()->create();
    $withoutLocation->forceFill(['ext_location_id' => null])->save();
    $withoutLocation->moduleAccess()->create(['module' => 'mitteilungen']);
    $inactive = channelMember($south);
    $inactive->update(['status' => 'inactive']);
    $noModule = channelMember($south, module: false);
    $adminOutside = User::factory()->admin()->create();
    $adminOutside->forceFill(['ext_location_id' => null])->save();

    $general = Announcement::create(['title' => 'Allgemein']);
    Announcement::create(['title' => 'Nur Nord', 'ext_location_id' => $north->id]);
    $southNotice = Announcement::create(['title' => 'Nur Sued', 'ext_location_id' => $south->id]);
    $service = app(AnnouncementService::class);

    expect(Announcement::for($northMember)->pluck('title')->all())->toContain('Allgemein', 'Nur Nord')->not->toContain('Nur Sued')
        ->and(Announcement::for($southMember)->pluck('title')->all())->toContain('Allgemein', 'Nur Sued')->not->toContain('Nur Nord')
        ->and(Announcement::for($multi)->pluck('title')->all())->toContain('Allgemein', 'Nur Nord', 'Nur Sued')
        ->and(Announcement::for($withoutLocation)->pluck('title')->all())->toBe(['Allgemein'])
        ->and($service->recipients($southNotice)->modelKeys())->toContain($southMember->id, $multi->id)
        ->and($service->recipients($southNotice)->modelKeys())->not->toContain($northMember->id, $inactive->id, $noModule->id, $adminOutside->id)
        ->and($service->recipients($general)->modelKeys())->toContain($withoutLocation->id);
});

it('rejects location-admin scope escalation and cross-location audience ids', function () {
    $north = Location::default();
    $south = channelLocation('Wache Sued');
    $editor = channelMember($north);
    DB::table('x_location_user')->where('user_id', $editor->id)->update(['role' => 'admin']);
    app(LocationAccess::class)->forget($editor);
    $otherGroup = Group::create(['name' => 'Sued']);
    $otherGroup->forceFill(['ext_location_id' => $south->id])->save();
    $service = app(AnnouncementService::class);

    expect(fn () => $service->save(null, ['title' => 'Allgemein', 'important' => false], [], $editor))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $service->save(null, ['title' => 'Fremde Abteilung', 'important' => false], [$otherGroup->id], $editor, locationId: (int) $north->id))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(Announcement::count())->toBe(0);
});

it('keeps a general notice targeted to a foreign location hidden from that editors management and statistics', function () {
    $north = Location::default();
    $south = channelLocation('Wache Sued');
    $northEditor = channelMember($north);
    $northEditor->moduleAccess()->where('module', 'mitteilungen')->update(['role' => 'redakteur']);
    $southMember = channelMember($south);
    $group = Group::create(['name' => 'Sued intern']);
    $group->forceFill(['ext_location_id' => $south->id])->save();
    $southMember->groups()->attach($group);
    $announcement = Announcement::create(['title' => 'Nur Sued, allgemein zugeordnet']);
    $announcement->groups()->attach($group);
    $service = app(AnnouncementService::class);

    expect(Announcement::for($southMember)->whereKey($announcement->id)->exists())->toBeTrue()
        ->and(Announcement::for($northEditor)->whereKey($announcement->id)->exists())->toBeFalse()
        ->and($service->canManage($northEditor, $announcement))->toBeFalse()
        ->and($service->managementQuery($northEditor)->whereKey($announcement->id)->exists())->toBeFalse()
        ->and($service->recipients($announcement)->modelKeys())->toBe([$southMember->id]);
});

it('serves location attachments and acknowledgement only to that location', function () {
    $north = Location::default();
    $south = channelLocation('Wache Sued');
    $northMember = channelMember($north);
    $southMember = channelMember($south);
    $announcement = Announcement::create(['title' => 'Sued intern', 'needs_ack' => true, 'ext_location_id' => $south->id]);
    $file = AnnouncementFile::create([
        'announcement_id' => $announcement->id, 'filename' => 'Hinweis.pdf', 'mime' => 'application/pdf',
        'size' => 10, 'data' => base64_encode('%PDF-1.4'),
    ]);
    $future = Announcement::create(['title' => 'Noch nicht sichtbar', 'visible_from' => now()->addDay(), 'ext_location_id' => $south->id]);
    $futureFile = AnnouncementFile::create([
        'announcement_id' => $future->id, 'filename' => 'Zukunft.pdf', 'mime' => 'application/pdf',
        'size' => 10, 'data' => base64_encode('%PDF-1.4'),
    ]);

    $this->actingAs($northMember)->get(route('mitteilungen.index'))->assertDontSee('Sued intern');
    $this->get(route('mitteilungen.file', $file))->assertForbidden();
    $this->post(route('mitteilungen.acknowledge', $announcement))->assertForbidden();

    $this->actingAs($southMember)->get(route('mitteilungen.index'))->assertSee('Sued intern');
    $this->get(route('mitteilungen.file', $file))->assertOk();
    $this->post(route('mitteilungen.acknowledge', $announcement))->assertRedirect();
    $this->get(route('mitteilungen.file', $futureFile))->assertForbidden();
    $this->post(route('mitteilungen.acknowledge', $future))->assertForbidden();
});

it('sends one ntfy request for an explicitly requested published notice and stores only an encrypted token', function () {
    Http::fake(['https://notify.example.com/*' => Http::response(['id' => 'message-id'], 200)]);
    $admin = User::factory()->admin()->create();
    $settings = app(ChannelSettings::class);
    $settings->save($admin, 'general', 'ntfy', [
        'enabled' => true, 'server_url' => 'https://notify.example.com', 'target' => 'wehr-allgemein', 'token' => 'tk_example-secret',
    ]);
    $stored = ChannelDestination::query()->where('scope_key', 'general')->where('transport', 'ntfy')->firstOrFail();
    expect($stored->getRawOriginal('token'))->not->toContain('tk_example-secret')
        ->and($settings->formValues('general', 'ntfy')['has_token'])->toBeTrue();

    $service = app(AnnouncementService::class);
    $data = ['title' => 'Probe', 'body' => 'UTF-8: Grüße', 'important' => false];
    $announcement = $service->save(null, $data, [], $admin, ntfy: true);
    $service->save($announcement, $data, [], $admin, ntfy: true);
    $updated = ['title' => 'Probe aktualisiert', 'body' => 'UTF-8: Grüße', 'important' => false];
    $service->save($announcement, $updated, [], $admin);
    $service->save($announcement, $updated, [], $admin, ntfy: true);
    $service->save($announcement, $updated, [], $admin, ntfy: true);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://notify.example.com/wehr-allgemein'
        && $request->hasHeader('Authorization')
        && $request->hasHeader('Title', 'Probe aktualisiert')
        && $request->body() === 'UTF-8: Grüße');
    expect(ChannelDelivery::query()->where('status', 'sent')->count())->toBe(2);
});

it('routes location notices only to their location target and does not fall back', function () {
    Http::fake(['https://notify.example.com/*' => Http::response([], 200)]);
    $north = Location::default();
    $south = channelLocation('Wache Sued');
    $west = channelLocation('Wache West');
    $admin = User::factory()->admin()->create();
    $settings = app(ChannelSettings::class);
    $settings->save($admin, 'general', 'ntfy', ['enabled' => true, 'server_url' => 'https://notify.example.com', 'target' => 'allgemein', 'token' => 'token-global']);
    $settings->save($admin, 'location:'.$north->id, 'ntfy', ['enabled' => true, 'server_url' => 'https://notify.example.com', 'target' => 'nord', 'token' => 'token-nord']);
    $settings->save($admin, 'location:'.$south->id, 'ntfy', ['enabled' => true, 'server_url' => 'https://notify.example.com', 'target' => 'sued', 'token' => 'token-sued']);

    $service = app(AnnouncementService::class);
    $service->save(null, ['title' => 'Nord', 'important' => false], [], $admin, ntfy: true, locationId: (int) $north->id);
    expect(Http::recorded()->last()[0]->url())->toBe('https://notify.example.com/nord');

    Http::fake(['https://notify.example.com/*' => Http::response([], 200)]);
    $service->save(null, ['title' => 'Sued', 'important' => false], [], $admin, ntfy: true, locationId: (int) $south->id);
    Http::assertSent(fn ($request) => $request->url() === 'https://notify.example.com/sued');

    Http::fake();
    $service->save(null, ['title' => 'West', 'important' => false], [], $admin, ntfy: true, locationId: (int) $west->id);
    Http::assertNothingSent();
});

it('skips narrow audiences, future notices and missing destinations without sending', function () {
    Http::fake();
    $admin = User::factory()->admin()->create();
    $function = MemberFunction::create(['name' => 'Maschinist']);
    $service = app(AnnouncementService::class);

    $service->save(null, ['title' => 'Vertraulich', 'important' => false], [], $admin, ntfy: true, functionIds: [$function->id]);
    $service->save(null, ['title' => 'Noch nicht', 'important' => false, 'visible_from' => now()->addDay()], [], $admin, ntfy: true);
    $service->save(null, ['title' => 'Ohne Ziel', 'important' => false], [], $admin, matrix: true);

    Http::assertNothingSent();
    expect($service->externalStatus)->toContain('Matrix: nicht gesendet – für diesen Geltungsbereich ist kein aktives Ziel vollständig eingerichtet.');
});

it('does not send any channel notification before publication', function () {
    Http::fake();
    Notification::fake();
    $admin = User::factory()->admin()->create();
    $service = app(AnnouncementService::class);

    $announcement = $service->save(null, [
        'title' => 'Noch nicht veröffentlicht',
        'important' => false,
        'visible_from' => now()->addDay(),
    ], [], $admin, mail: true, push: true, ntfy: true, matrix: true);

    expect($announcement->exists)->toBeTrue()
        ->and($service->externalStatus)->toContain(
            'ntfy: nicht gesendet – die Mitteilung ist noch nicht oder nicht mehr veröffentlicht.',
            'Matrix: nicht gesendet – die Mitteilung ist noch nicht oder nicht mehr veröffentlicht.',
        );
    Notification::assertNothingSent();
    Http::assertNothingSent();
});

it('encodes Matrix room IDs, retries with a stable transaction ID, and refuses encrypted rooms', function () {
    $admin = User::factory()->admin()->create();
    app(ChannelSettings::class)->save($admin, 'general', 'matrix', [
        'enabled' => true, 'server_url' => 'https://matrix.example.com', 'target' => '!room-id:example.com', 'token' => 'syt_example-secret',
    ]);
    $attempt = 0;
    Http::fake(function ($request) use (&$attempt) {
        if (str_contains($request->url(), '/state/m.room.encryption')) {
            return Http::response(['errcode' => 'M_NOT_FOUND'], 404);
        }
        $attempt++;

        return Http::response(['errcode' => 'M_UNKNOWN'], $attempt === 1 ? 500 : 200);
    });
    $service = app(AnnouncementService::class);
    $announcement = $service->save(null, ['title' => 'Matrix-Test', 'important' => false], [], $admin, matrix: true);
    $service->save($announcement, ['title' => 'Matrix-Test', 'important' => false], [], $admin, matrix: true);

    $puts = Http::recorded()->filter(fn ($pair) => $pair[0]->method() === 'PUT');
    expect($puts)->toHaveCount(2)
        ->and($puts->map(fn ($pair) => $pair[0]->url())->unique()->count())->toBe(1)
        ->and($puts->first()[0]->url())->toContain('%21room-id%3Aexample.com')
        ->and($puts->first()[0]->url())->toMatch('#/send/m\.room\.message/ow[a-f0-9]{62}$#');
    $record = ChannelDelivery::sole();
    expect($record->status)->toBe('sent')->and($record->attempts)->toBe(2);

    Http::fake([
        'https://matrix.example.com/*/state/m.room.encryption' => Http::response(['algorithm' => 'm.megolm.v1.aes-sha2'], 200),
        'https://matrix.example.com/*' => Http::response([], 200),
    ]);
    $service->save(null, ['title' => 'Verschlüsselt', 'important' => false], [], $admin, matrix: true);
    expect($service->externalStatus)->toContain('Matrix: nicht gesendet – der Raum ist verschlüsselt. Für E2EE-Versand ist diese Integration nicht geeignet.')
        ->and(ChannelDelivery::latest('id')->firstOrFail()->result)->toContain('verschlüsselt');
    Http::assertSentCount(1);
});

it('rejects unsafe and overlapping destinations', function () {
    $admin = User::factory()->admin()->create();
    SafeUrl::resolveUsing(fn () => ['127.0.0.1']);

    expect(fn () => app(ChannelSettings::class)->save($admin, 'general', 'ntfy', [
        'enabled' => true, 'server_url' => 'https://127.0.0.1', 'target' => 'secret', 'token' => 'token',
    ]))->toThrow(ValidationException::class);

    SafeUrl::resolveUsing(fn () => ['93.184.216.34']);
    $settings = app(ChannelSettings::class);
    $settings->save($admin, 'general', 'ntfy', ['enabled' => true, 'server_url' => 'https://notify.example.com', 'target' => 'same', 'token' => 'token']);
    expect(fn () => $settings->save($admin, 'location:1', 'ntfy', [
        'enabled' => true, 'server_url' => 'https://notify.example.com', 'target' => 'same', 'token' => 'token',
    ]))->toThrow(ValidationException::class);
});

it('reports ntfy authentication and rate-limit failures without retrying uncertain delivery', function () {
    $admin = User::factory()->admin()->create();
    app(ChannelSettings::class)->save($admin, 'general', 'ntfy', [
        'enabled' => true, 'server_url' => 'https://notify.example.com', 'target' => 'wehr-test', 'token' => 'fixture-token',
    ]);
    Http::fakeSequence('https://notify.example.com/*')
        ->push([], 401)
        ->push([], 429);
    $service = app(AnnouncementService::class);
    $service->save(null, ['title' => 'Authfehler', 'important' => false], [], $admin, ntfy: true);
    expect($service->externalStatus)->toContain('ntfy: Zugriff abgelehnt; ntfy-Token und Topic-Berechtigung prüfen.');

    $announcement = $service->save(null, ['title' => 'Drosseltest', 'important' => false], [], $admin, ntfy: true);

    expect($service->externalStatus)->toContain('ntfy: Der ntfy-Server begrenzt den Versand; denselben Nachrichtenstand nicht erneut senden. Nach Prüfung bei Bedarf eine neue Mitteilung anlegen.')
        ->and(ChannelDelivery::query()->where('announcement_id', $announcement->id)->sole()->status)->toBe('failed');
    $service->save($announcement, ['title' => 'Drosseltest', 'important' => false], [], $admin, ntfy: true);
    Http::assertSentCount(2);
});

it('contains transport timeouts and keeps the saved announcement', function () {
    $admin = User::factory()->admin()->create();
    app(ChannelSettings::class)->save($admin, 'general', 'ntfy', [
        'enabled' => true, 'server_url' => 'https://notify.example.com', 'target' => 'timeout', 'token' => 'fixture-token',
    ]);
    Http::fake(fn () => throw new ConnectionException('timeout'));

    $service = app(AnnouncementService::class);
    $announcement = $service->save(null, ['title' => 'Zeitüberschreitung', 'important' => false], [], $admin, ntfy: true);

    expect($announcement->exists)->toBeTrue()
        ->and($service->externalStatus)->toContain('ntfy: Verbindung fehlgeschlagen oder Server nicht zulässig. Einstellungen prüfen.')
        ->and(ChannelDelivery::sole()->status)->toBe('failed');
});

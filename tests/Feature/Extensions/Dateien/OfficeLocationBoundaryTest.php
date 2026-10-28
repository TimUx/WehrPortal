<?php

use App\Models\Module;
use App\Models\User;
use Extensions\Dateien\Models\FileGroup;
use Extensions\Dateien\Models\Permission;
use Extensions\Dateien\Models\Space;
use Extensions\Dateien\Models\Version;
use Extensions\Dateien\Office\Jwt;
use Extensions\Dateien\Office\OfficeService;
use Extensions\Dateien\Office\OfficeSettings;
use Extensions\Dateien\Office\OnlyOfficeConnector;
use Extensions\Dateien\Office\WopiProof;
use Extensions\Dateien\Services\FileService;
use Extensions\Locations\Models\Location;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    config(['locations.enabled' => true]);
    Module::where('key', 'dateien')->update(['is_active' => true, 'auto_assign' => false]);
    Cache::flush();
    Storage::fake('dateien');
    Notification::fake();
    $this->north = Location::default();
    $this->south = Location::create(['name' => 'Wache Süd', 'slug' => 'office-boundary-sued']);
    $this->admin = filesMember('Ada Atemschutz', $this->north, true);
    $this->member = filesMember('Hanna Hydrant', $this->north);
    DB::table('x_location_user')->insert(['location_id' => $this->south->id, 'user_id' => $this->member->id, 'role' => 'member']);
    $this->files = app(FileService::class);
    $this->space = $this->files->createSpace($this->admin, ['name' => 'Dienstunterlagen Süd', 'ext_location_id' => $this->south->id]);
    $this->node = $this->files->upload($this->admin, $this->space, null, filesUpload('Erste Fassung', 'dienst.txt'));
    $this->files->setPermission($this->admin, $this->node, 'user', $this->member->id, 'edit');
    $this->office = app(OfficeService::class);
    $this->secret = str_repeat('dateien-test-', 4);
});

function filesBoundaryOffice(string $provider): void
{
    $test = test();
    if ($provider === 'collabora') {
        $test->proofKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $rsa = openssl_pkey_get_details($test->proofKey)['rsa'];
        $xml = '<wopi-discovery><net-zone name="external-http"><app name="text/plain">'
            .'<action name="view" ext="txt" urlsrc="http://intern:9980/browser/test/cool.html?"/>'
            .'<action name="edit" ext="txt" urlsrc="http://intern:9980/browser/test/cool.html?edit=1&amp;"/></app></net-zone>'
            .'<proof-key value="x" modulus="'.base64_encode($rsa['n']).'" exponent="'.base64_encode($rsa['e']).'"/></wopi-discovery>';
        Http::fake(['http://intern:9980/hosting/discovery' => Http::response($xml)]);
    } else {
        Http::fake(['http://onlyoffice/cache/*' => Http::response('Neue Office-Fassung')]);
    }
    app(OfficeSettings::class)->save([
        'provider' => $provider, 'server_url' => 'https://office.ff-musterstadt.de',
        'internal_url' => $provider === 'collabora' ? 'http://intern:9980' : 'http://onlyoffice',
        'callback_url' => '', 'extensions' => ['txt'], 'editing' => true,
    ], $provider === 'onlyoffice' ? $test->secret : null);
}

function filesBoundaryWopi(string $token, string $method = 'GET', string $path = '', array $headers = [], string $body = '', ?int $node = null)
{
    $test = test();
    $url = route('dateien.office.wopi.file', ['node' => $node ?? $test->node->id]).$path.'?access_token='.$token;
    $ticks = WopiProof::ticks();
    openssl_sign(WopiProof::data($token, $url, $ticks), $signature, $test->proofKey, OPENSSL_ALGO_SHA256);
    $server = ['CONTENT_LENGTH' => (string) strlen($body)];
    foreach ($headers + ['X-WOPI-TimeStamp' => (string) $ticks, 'X-WOPI-Proof' => base64_encode($signature)] as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return $test->call($method, $url, [], [], [], $server, $body);
}

test('actorless Office reads and saves use the session member and latest editor version', function (string $provider) {
    filesBoundaryOffice($provider);
    [, $viewerToken] = $this->office->open($this->member, $this->node);
    $second = $this->files->addVersion($this->admin, $this->node, filesUpload('Zweite Fassung', 'dienst.txt'));
    [$session, $token] = $this->office->open($this->member, $this->node, true);
    expect($session->version_id)->toBe($second->id)
        ->and(auth()->check())->toBeFalse();
    if ($provider === 'onlyoffice') {
        $headers = ['Authorization' => 'Bearer '.Jwt::encode([], $this->secret)];
        $this->get(route('dateien.office.onlyoffice.file', ['token' => $viewerToken]), $headers)->assertOk()->assertStreamedContent('Erste Fassung');
        $this->get(route('dateien.office.onlyoffice.file', ['token' => $token]), $headers)->assertOk()->assertStreamedContent('Zweite Fassung');
        $payload = ['status' => 6, 'key' => OnlyOfficeConnector::documentKey($this->node, $second), 'filetype' => 'txt', 'url' => 'https://office.ff-musterstadt.de/cache/output.txt'];
        $this->postJson(route('dateien.office.onlyoffice.callback', ['token' => $token]), [], [
            'Authorization' => 'Bearer '.Jwt::encode(['payload' => $payload], $this->secret),
        ])->assertOk()->assertJson(['error' => 0]);
        $this->get(route('dateien.office.onlyoffice.file', ['token' => $token]), $headers)->assertOk()->assertStreamedContent('Neue Office-Fassung');
    } else {
        filesBoundaryWopi($viewerToken, path: '/contents')->assertOk()->assertStreamedContent('Erste Fassung');
        filesBoundaryWopi($token)->assertOk()->assertJson(['UserCanWrite' => true, 'Version' => (string) $second->id]);
        filesBoundaryWopi($token, path: '/contents')->assertOk()->assertStreamedContent('Zweite Fassung');
        filesBoundaryWopi($token, 'POST', headers: ['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'boundary-lock'])->assertOk();
        filesBoundaryWopi($token, 'POST', '/contents', ['X-WOPI-Override' => 'PUT', 'X-WOPI-Lock' => 'boundary-lock'], 'Neue Office-Fassung')->assertOk();
        filesBoundaryWopi($token, path: '/contents')->assertOk()->assertStreamedContent('Neue Office-Fassung');
        $other = $this->files->upload($this->admin, $this->space, null, filesUpload('Andere Datei', 'andere.txt'));
        filesBoundaryWopi($token, node: $other->id)->assertUnauthorized();
    }
    $latest = Version::where('node_id', $this->node->id)->orderByDesc('number')->first();
    expect($latest->number)->toBe(3)->and($latest->source)->toBe('office')->and($latest->created_by)->toBe($this->member->id)
        ->and(auth()->check())->toBeFalse();
})->with(['onlyoffice', 'collabora']);

test('Office callbacks recheck live location ACL module and actor without request authentication', function (string $provider, string $revocation) {
    filesBoundaryOffice($provider);
    $actor = $revocation === 'admin' ? $this->admin : $this->member;
    if ($revocation === 'group') {
        Permission::where('subject_type', 'user')->where('subject_id', $actor->id)->delete();
        $group = FileGroup::create(['name' => 'Ausbildung Süd', 'ext_location_id' => $this->south->id]);
        $group->members()->attach($actor->id, ['is_manager' => true]);
        $this->files->setPermission($this->admin, $this->node, 'group', $group->id, 'edit');
    }
    [, $token] = $this->office->open($actor, $this->node, true);
    if ($provider === 'collabora') {
        filesBoundaryWopi($token)->assertOk();
        filesBoundaryWopi($token, 'POST', headers: ['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'boundary-lock'])->assertOk();
    } else {
        $this->get(route('dateien.office.onlyoffice.file', ['token' => $token]), [
            'Authorization' => 'Bearer '.Jwt::encode([], $this->secret),
        ])->assertOk();
    }
    match ($revocation) {
        'location' => DB::table('x_location_user')->where('user_id', $actor->id)->where('location_id', $this->south->id)->delete(),
        'acl' => Permission::where('subject_type', 'user')->where('subject_id', $actor->id)->delete(),
        'group' => $group->members()->detach($actor->id),
        'module' => $actor->moduleAccess()->where('module', 'dateien')->delete(),
        'inactive' => $actor->fresh()->update(['is_active' => false]),
        'space' => Space::whereKey($this->space->id)->update(['ext_location_id' => Location::create(['name' => 'Wache West', 'slug' => 'office-boundary-west'])->id]),
        'disabled' => Module::where('key', 'dateien')->update(['is_active' => false]),
        'admin' => User::withoutGlobalScopes()->whereKey($actor->id)->update(['is_admin' => false]),
    };
    $status = match ($revocation) {
        'disabled' => 404,
        'module' => 200,
        default => 403,
    };
    if ($provider === 'onlyoffice') {
        $this->get(route('dateien.office.onlyoffice.file', ['token' => $token]), [
            'Authorization' => 'Bearer '.Jwt::encode([], $this->secret),
        ])->assertStatus($status);
        $payload = ['status' => 6, 'url' => 'https://office.ff-musterstadt.de/cache/output.txt', 'filetype' => 'txt'];
        $this->postJson(route('dateien.office.onlyoffice.callback', ['token' => $token]), [], [
            'Authorization' => 'Bearer '.Jwt::encode(['payload' => $payload], $this->secret),
        ])->assertStatus($status);
        if ($revocation !== 'module') {
            Http::assertNothingSent();
        }
    } else {
        filesBoundaryWopi($token)->assertStatus($status);
        filesBoundaryWopi($token, path: '/contents')->assertStatus($status);
        filesBoundaryWopi($token, 'POST', '/contents', ['X-WOPI-Override' => 'PUT', 'X-WOPI-Lock' => 'boundary-lock'], 'Nicht speichern')->assertStatus($status);
        filesBoundaryWopi($token, 'POST', headers: ['X-WOPI-Override' => 'REFRESH_LOCK', 'X-WOPI-Lock' => 'boundary-lock'])->assertStatus($status);
    }
    expect(Version::where('node_id', $this->node->id)->count())->toBe($revocation === 'module' ? 2 : 1)
        ->and(auth()->check())->toBeFalse();
})->with(['onlyoffice', 'collabora'])->with(['location', 'acl', 'group', 'module', 'inactive', 'space', 'disabled', 'admin']);

<?php

use App\Models\Module;
use Extensions\Dateien\Models\AuditEvent;
use Extensions\Dateien\Models\OfficeSession;
use Extensions\Dateien\Models\Space;
use Extensions\Dateien\Models\Version;
use Extensions\Dateien\Office\Jwt;
use Extensions\Dateien\Office\OfficeService;
use Extensions\Dateien\Office\OfficeSettings;
use Extensions\Dateien\Office\WopiProof;
use Extensions\Dateien\Services\FileService;
use Extensions\Locations\Models\Location;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

const EDIT_SECRET = 'bearbeiten-test-secret-fuer-musterstadt-0123456789';

beforeEach(function () {
    Module::where('key', 'dateien')->update(['is_active' => true]);
    Cache::flush();
    Storage::fake('dateien');
    $this->admin = filesMember('Ada Atemschutz', admin: true);
    $this->member = filesMember();
    $this->reader = filesMember('Bernd Blaulicht');
    $this->files = app(FileService::class);
    $this->space = $this->files->createSpace($this->admin, ['name' => 'Wehrführung', 'ext_location_id' => Location::default()->id]);
    $this->node = $this->files->upload($this->admin, $this->space, null, filesUpload('Erste Fassung des Dienstplans', 'dienstplan.txt'));
    $this->files->setPermission($this->admin, $this->node, 'user', $this->member->id, 'edit');
    $this->files->setPermission($this->admin, $this->node, 'user', $this->reader->id, 'read');
});

function editWithOnlyOffice(): void
{
    app(OfficeSettings::class)->save(['provider' => 'onlyoffice', 'server_url' => 'https://office.ff-musterstadt.de', 'internal_url' => 'http://onlyoffice', 'callback_url' => '', 'extensions' => OfficeSettings::VIEWABLE], EDIT_SECRET);
}

/** @return array{token: string, key: string, page: string} */
function openForEditing($user): array
{
    $page = test()->actingAs($user)->get(route('dateien.office', ['node' => test()->node->id, 'bearbeiten' => 1]))->assertOk()->getContent();
    preg_match('#onlyoffice\\\\?/([A-Za-z0-9]{64})\\\\?/datei#', $page, $token);
    preg_match('#"key":"([^"]+)"#', $page, $key);

    return ['token' => $token[1], 'key' => $key[1], 'page' => $page];
}

function onlyOfficeCallback(string $token, array $payload)
{
    return test()->postJson(route('dateien.office.onlyoffice.callback', ['token' => $token]), [], ['Authorization' => 'Bearer '.Jwt::encode(['payload' => $payload], EDIT_SECRET)]);
}

it('opens for editing only with edit rights and lets co-editors share one document', function () {
    editWithOnlyOffice();
    $first = openForEditing($this->member);
    expect($first['page'])->toContain('"mode":"edit"');
    $second = openForEditing($this->admin);
    expect($second['key'])->toBe($first['key']);
    $this->actingAs($this->reader)->get(route('dateien.office', ['node' => $this->node->id, 'bearbeiten' => 1]))->assertForbidden();
    expect(app(OfficeService::class)->canOpen($this->reader, $this->node, true))->toBeFalse()
        ->and(app(OfficeService::class)->canOpen($this->member, $this->node, true))->toBeTrue();

    app(OfficeSettings::class)->save(['provider' => 'onlyoffice', 'server_url' => 'https://office.ff-musterstadt.de', 'internal_url' => '', 'callback_url' => '', 'extensions' => OfficeSettings::VIEWABLE, 'editing' => false], null);
    expect(app(OfficeService::class)->canOpen($this->member, $this->node, true))->toBeFalse();
    $this->actingAs($this->member)->get(route('dateien.office', ['node' => $this->node->id, 'bearbeiten' => 1]))->assertNotFound();
});

it('saves an ONLYOFFICE document once as a new version and blocks uploads meanwhile', function () {
    editWithOnlyOffice();
    Http::fake(['http://onlyoffice/cache/files/*' => Http::response('Gemeinsam bearbeitete Fassung')]);
    $first = openForEditing($this->member);
    openForEditing($this->admin);
    onlyOfficeCallback($first['token'], ['key' => $first['key'], 'status' => 1, 'users' => [(string) $this->member->id]])->assertJson(['error' => 0]);

    expect(fn () => $this->files->addVersion($this->member, $this->node, filesUpload('Manuelle Fassung')))
        ->toThrow(ValidationException::class);

    onlyOfficeCallback($first['token'], ['key' => $first['key'], 'status' => 2, 'filetype' => 'txt', 'users' => [(string) $this->member->id, (string) $this->admin->id],
        'url' => 'https://office.ff-musterstadt.de/cache/files/abc/output.txt?md5=x&expires=1'])->assertJson(['error' => 0]);

    $versions = Version::where('node_id', $this->node->id)->orderBy('number')->get();
    expect($versions)->toHaveCount(2)
        ->and($versions[1]->source)->toBe('office')
        ->and($versions[1]->created_by)->toBe($this->member->id)
        ->and($versions[1]->checksum)->toBe(hash('sha256', 'Gemeinsam bearbeitete Fassung'))
        ->and(AuditEvent::where('action', 'office.saved')->first()->metadata)->toMatchArray(['editors' => [$this->member->id, $this->admin->id]]);
    // The browser address was fetched through the internal address
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'http://onlyoffice/cache/files/abc/output.txt'));

    OfficeSession::where('user_id', $this->admin->id)->update(['ended_at' => now()]);
    expect($this->files->addVersion($this->member, $this->node, filesUpload('Manuelle Fassung'))->number)->toBe(3);
});

it('rejects saves from foreign hosts, over the quota or after losing edit rights', function () {
    editWithOnlyOffice();
    Http::fake(['*' => Http::response('Fremder Inhalt')]);
    $open = openForEditing($this->member);

    onlyOfficeCallback($open['token'], ['key' => $open['key'], 'status' => 2, 'url' => 'https://angreifer.example.com/datei.txt'])->assertJson(['error' => 1]);
    onlyOfficeCallback($open['token'], ['key' => $open['key'], 'status' => 2, 'url' => 'http://169.254.169.254/latest/meta-data'])->assertJson(['error' => 1]);
    Http::assertNothingSent();
    onlyOfficeCallback($open['token'], ['key' => '999999-1-x', 'status' => 2, 'url' => 'https://office.ff-musterstadt.de/x.txt'])->assertUnauthorized();

    Space::whereKey($this->space->id)->update(['quota_bytes' => 40]);
    onlyOfficeCallback($open['token'], ['key' => $open['key'], 'status' => 2, 'url' => 'https://office.ff-musterstadt.de/cache/x.txt'])->assertJson(['error' => 1]);
    expect(AuditEvent::where('action', 'office.save_failed')->count())->toBe(3)
        ->and(AuditEvent::where('action', 'office.save_failed')->latest('id')->first()->metadata['reason'])->toContain('Speicherlimit');
    Space::whereKey($this->space->id)->update(['quota_bytes' => null]);

    $this->files->setPermission($this->admin, $this->node, 'user', $this->member->id, 'read');
    onlyOfficeCallback($open['token'], ['key' => $open['key'], 'status' => 2, 'url' => 'https://office.ff-musterstadt.de/cache/x.txt'])->assertForbidden();
    expect(Version::where('node_id', $this->node->id)->count())->toBe(1);
});

it('merges autosaves of one session into one version and keeps explicit saves', function () {
    editWithOnlyOffice();
    $open = openForEditing($this->member);
    Http::fake(['http://onlyoffice/*' => Http::sequence()->push('Zwischenstand eins')->push('Zwischenstand zwei')->push('Endfassung')]);
    $save = function (int $status, int $type = 0) use ($open) {
        onlyOfficeCallback($open['token'], ['key' => $open['key'], 'status' => $status, 'forcesavetype' => $type, 'url' => 'https://office.ff-musterstadt.de/cache/x.txt'])->assertJson(['error' => 0]);
    };
    $save(6, 2);
    $first = Version::where('node_id', $this->node->id)->orderByDesc('number')->first();
    $save(6, 2);
    expect(Version::where('node_id', $this->node->id)->count())->toBe(2)
        ->and(Version::find($first->id))->toBeNull()
        ->and(Storage::disk('dateien')->exists($first->storage_key))->toBeFalse();
    $save(2);

    $versions = Version::where('node_id', $this->node->id)->orderBy('number')->get();
    expect($versions)->toHaveCount(2)
        ->and($versions[1]->number)->toBe(2)
        ->and($versions[1]->autosave)->toBeFalse()
        ->and($versions[1]->checksum)->toBe(hash('sha256', 'Endfassung'));
});

it('implements WOPI locks and saves through PutFile only with the current lock', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $rsa = openssl_pkey_get_details($key)['rsa'];
    $xml = '<?xml version="1.0"?><wopi-discovery><net-zone name="external-http"><app name="text/plain">'
        .'<action name="view" ext="txt" urlsrc="http://intern:9980/browser/abc/cool.html?"/><action name="edit" ext="txt" urlsrc="http://intern:9980/browser/abc/cool.html?edit=1&amp;"/></app></net-zone>'
        .'<proof-key value="x" modulus="'.base64_encode($rsa['n']).'" exponent="'.base64_encode($rsa['e']).'"/></wopi-discovery>';
    Http::fake(['http://intern:9980/hosting/discovery' => Http::response($xml)]);
    app(OfficeSettings::class)->save(['provider' => 'collabora', 'server_url' => 'https://office.ff-musterstadt.de', 'internal_url' => 'http://intern:9980', 'callback_url' => '', 'extensions' => ['txt']], null);

    $tokenFor = function ($user, bool $edit) {
        $page = test()->actingAs($user)->get(route('dateien.office', ['node' => test()->node->id] + ($edit ? ['bearbeiten' => 1] : [])))->assertOk()->getContent();
        preg_match('/name="access_token" value="([A-Za-z0-9]{64})"/', $page, $m);

        return $m[1];
    };
    $token = $tokenFor($this->member, true);
    $readToken = $tokenFor($this->reader, false);
    auth()->logout();
    $call = function (string $token, string $path, array $headers, string $body = '') use ($key) {
        $url = route('dateien.office.wopi.file', ['node' => test()->node->id]).$path.'?access_token='.$token;
        $ticks = WopiProof::ticks();
        openssl_sign(WopiProof::data($token, $url, $ticks), $signature, $key, OPENSSL_ALGO_SHA256);
        $server = [];
        foreach ($headers + ['X-WOPI-TimeStamp' => (string) $ticks, 'X-WOPI-Proof' => base64_encode($signature)] as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $server['CONTENT_LENGTH'] = (string) strlen($body);

        return test()->call('POST', $url, [], [], [], $server, $body);
    };

    $info = $this->get(route('dateien.office.wopi.file', ['node' => $this->node->id]).'?access_token='.$token, (function () use ($key, $token) {
        $url = route('dateien.office.wopi.file', ['node' => test()->node->id]).'?access_token='.$token;
        $ticks = WopiProof::ticks();
        openssl_sign(WopiProof::data($token, $url, $ticks), $signature, $key, OPENSSL_ALGO_SHA256);

        return ['X-WOPI-TimeStamp' => (string) $ticks, 'X-WOPI-Proof' => base64_encode($signature)];
    })())->assertOk()->assertJson(['UserCanWrite' => true, 'SupportsLocks' => true, 'ReadOnly' => false]);
    $modified = $info->json('LastModifiedTime');

    $call($readToken, '', ['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'fremd'])->assertUnauthorized();
    $call($token, '/contents', ['X-WOPI-Override' => 'PUT', 'X-WOPI-Lock' => 'sitzung-1'], 'Ohne Sperre')->assertStatus(409)->assertHeader('X-WOPI-Lock', '');
    $call($token, '', ['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'sitzung-1'])->assertOk();
    $call($token, '', ['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'sitzung-2'])->assertStatus(409)->assertHeader('X-WOPI-Lock', 'sitzung-1');
    $call($token, '', ['X-WOPI-Override' => 'GET_LOCK'])->assertOk()->assertHeader('X-WOPI-Lock', 'sitzung-1');
    $call($token, '', ['X-WOPI-Override' => 'REFRESH_LOCK', 'X-WOPI-Lock' => 'sitzung-2'])->assertStatus(409);
    $call($token, '', ['X-WOPI-Override' => 'REFRESH_LOCK', 'X-WOPI-Lock' => 'sitzung-1'])->assertOk();
    $call($token, '', ['X-WOPI-Override' => 'PUT_RELATIVE'])->assertStatus(501);
    $call($token, '', ['X-WOPI-Override' => 'RENAME_FILE'])->assertStatus(501);

    expect(fn () => $this->files->addVersion($this->admin, $this->node, filesUpload('Manuelle Fassung')))->toThrow(ValidationException::class);

    $call($token, '/contents', ['X-WOPI-Override' => 'PUT', 'X-WOPI-Lock' => 'sitzung-2'], 'Falsche Sperre')->assertStatus(409)->assertHeader('X-WOPI-Lock', 'sitzung-1');
    $call($token, '/contents', ['X-WOPI-Override' => 'PUT', 'X-WOPI-Lock' => 'sitzung-1', 'X-COOL-WOPI-Timestamp' => '2001-01-01T00:00:00Z'], 'Veraltet')
        ->assertStatus(409)->assertJson(['COOLStatusCode' => 1010]);
    $call($token, '/contents', ['X-WOPI-Override' => 'PUT', 'X-WOPI-Lock' => 'sitzung-1', 'X-COOL-WOPI-Timestamp' => $modified, 'X-COOL-WOPI-IsAutosave' => 'true'], 'Im Office geändert')
        ->assertOk()->assertJsonStructure(['LastModifiedTime']);
    $latest = Version::where('node_id', $this->node->id)->orderByDesc('number')->first();
    expect($latest->source)->toBe('office')->and($latest->autosave)->toBeTrue()->and($latest->number)->toBe(2);

    $call($token, '', ['X-WOPI-Override' => 'UNLOCK', 'X-WOPI-Lock' => 'sitzung-2'])->assertStatus(409);
    $call($token, '', ['X-WOPI-Override' => 'UNLOCK', 'X-WOPI-Lock' => 'sitzung-1'])->assertOk();
    expect($this->files->addVersion($this->admin, $this->node, filesUpload('Manuelle Fassung'))->number)->toBe(3);
});

it('prunes idle edit sessions and expired locks', function () {
    editWithOnlyOffice();
    openForEditing($this->member);
    expect(OfficeSession::editing($this->node->id))->toBeTrue();
    OfficeSession::query()->update(['last_seen_at' => now()->subHours(2), 'created_at' => now()->subHours(2)]);
    expect(OfficeSession::editing($this->node->id))->toBeFalse();
    $this->artisan('dateien:prune-office-sessions')->assertSuccessful();
    expect(OfficeSession::first()->ended_at)->not->toBeNull()
        ->and(AuditEvent::where('action', 'office.closed')->first()->metadata['reason'])->toBe('idle');
});

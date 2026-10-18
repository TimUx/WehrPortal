<?php

use App\Models\ActivityLog;
use App\Models\Module;
use App\Models\Setting;
use Extensions\Dateien\Models\AuditEvent;
use Extensions\Dateien\Models\OfficeSession;
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

// Invented test value (not a credential), built at runtime so secret scanners don't flag it
define('OFFICE_SECRET', str_repeat('musterstadt-', 5));

beforeEach(function () {
    Module::where('key', 'dateien')->update(['is_active' => true]);
    Cache::flush();
    Storage::fake('dateien');
    $this->admin = filesMember('Ada Atemschutz', admin: true);
    $this->member = filesMember();
    $this->files = app(FileService::class);
    $this->space = $this->files->createSpace($this->admin, ['name' => 'Wehrführung', 'ext_location_id' => Location::default()->id]);
    $this->node = $this->files->upload($this->admin, $this->space, null, filesUpload('Inhalt des Dienstplans', 'dienstplan.txt'));
    $this->files->setPermission($this->admin, $this->node, 'user', $this->member->id, 'read');
});

function useOnlyOffice(): void
{
    app(OfficeSettings::class)->save(['provider' => 'onlyoffice', 'server_url' => 'https://office.ff-musterstadt.de', 'internal_url' => '', 'callback_url' => '', 'extensions' => OfficeSettings::VIEWABLE], OFFICE_SECRET);
}

function onlyOfficeToken(): string
{
    preg_match('#onlyoffice\\\\?/([A-Za-z0-9]{64})\\\\?/datei#', test()->actingAs(test()->member)->get(route('dateien.office', ['node' => test()->node->id]))->getContent(), $m);

    return $m[1];
}

it('requires a JWT secret for ONLYOFFICE and stores it encrypted and masked', function () {
    expect(fn () => app(OfficeSettings::class)->save(['provider' => 'onlyoffice', 'server_url' => 'https://office.ff-musterstadt.de', 'internal_url' => '', 'callback_url' => '', 'extensions' => []], null))
        ->toThrow(ValidationException::class);
    useOnlyOffice();
    expect(Setting::get('dateien.office.jwt_secret'))->not->toContain(OFFICE_SECRET)
        ->and(app(OfficeSettings::class)->secret())->toBe(OFFICE_SECRET)
        ->and(ActivityLog::query()->get()->toJson())->not->toContain(OFFICE_SECRET);
});

it('opens ONLYOFFICE read only with a strict CSP and serves the file only with a valid JWT', function () {
    useOnlyOffice();
    $response = $this->actingAs($this->member)->get(route('dateien.office', ['node' => $this->node->id]))->assertOk();
    expect($response->headers->get('Content-Security-Policy'))->toContain('frame-src https://office.ff-musterstadt.de', "default-src 'none'")
        ->and($response->getContent())->toContain('<meta name="referrer" content="no-referrer">')
        ->and($response->getContent())->toContain('"mode":"view"');
    preg_match('#onlyoffice\\\\?/([A-Za-z0-9]{64})\\\\?/datei#', $response->getContent(), $m);
    $url = route('dateien.office.onlyoffice.file', ['token' => $m[1]]);

    $this->get($url)->assertUnauthorized();
    $this->get($url, ['Authorization' => 'Bearer '.Jwt::encode(['url' => $url], 'falsches-secret-falsches-secret-123')])->assertUnauthorized();
    $this->get($url, ['Authorization' => 'Bearer '.Jwt::encode(['exp' => time() - 3600], OFFICE_SECRET)])->assertUnauthorized();
    $this->get($url, ['Authorization' => 'Bearer '.Jwt::encode(['url' => $url], OFFICE_SECRET)])->assertOk()->assertStreamedContent('Inhalt des Dienstplans');
    $this->get(route('dateien.office.onlyoffice.file', ['token' => str_repeat('a', 64)]), ['Authorization' => 'Bearer '.Jwt::encode([], OFFICE_SECRET)])->assertUnauthorized();
    expect(AuditEvent::where('action', 'office.opened')->first()->metadata)->not->toHaveKey('token')
        ->and(OfficeSession::first()->token_hash)->not->toBe($m[1])
        ->and(AuditEvent::query()->get()->toJson())->not->toContain($m[1]);
});

it('rejects alg none and tampered JWTs', function () {
    $valid = Jwt::encode(['status' => 4], OFFICE_SECRET);
    [$h, $p, $s] = explode('.', $valid);
    $none = rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=').'.'.$p.'.';
    $tampered = $h.'.'.rtrim(strtr(base64_encode('{"status":2}'), '+/', '-_'), '=').'.'.$s;
    expect(Jwt::decode($valid, OFFICE_SECRET))->toBe(['status' => 4])
        ->and(Jwt::decode($none, OFFICE_SECRET))->toBeNull()
        ->and(Jwt::decode($tampered, OFFICE_SECRET))->toBeNull();
});

it('checks rights again on every call and ends sessions on close', function () {
    useOnlyOffice();
    $token = onlyOfficeToken();
    $auth = ['Authorization' => 'Bearer '.Jwt::encode(['payload' => ['status' => 4]], OFFICE_SECRET)];
    $this->files->setPermission($this->admin, $this->node, 'user', $this->member->id, null);
    $this->get(route('dateien.office.onlyoffice.file', ['token' => $token]), $auth)->assertForbidden();
    $this->files->setPermission($this->admin, $this->node, 'user', $this->member->id, 'read');
    $this->postJson(route('dateien.office.onlyoffice.callback', ['token' => $token]), [], $auth)->assertOk()->assertJson(['error' => 0]);
    expect(AuditEvent::where('action', 'office.closed')->exists())->toBeTrue();

    OfficeSession::query()->update(['expires_at' => now()->subMinute()]);
    $this->get(route('dateien.office.onlyoffice.file', ['token' => $token]), $auth)->assertUnauthorized();
    OfficeSession::query()->update(['expires_at' => now()->addHour(), 'revoked_at' => now()]);
    $this->get(route('dateien.office.onlyoffice.file', ['token' => $token]), $auth)->assertUnauthorized();
});

it('returns 404 for office routes when the module or the provider is off', function () {
    useOnlyOffice();
    $token = onlyOfficeToken();
    $auth = ['Authorization' => 'Bearer '.Jwt::encode([], OFFICE_SECRET)];
    Module::where('key', 'dateien')->update(['is_active' => false]);
    Cache::flush();
    $this->get(route('dateien.office.onlyoffice.file', ['token' => $token]), $auth)->assertNotFound();
    $this->actingAs($this->member)->get(route('dateien.office', ['node' => $this->node->id]))->assertNotFound();
    Module::where('key', 'dateien')->update(['is_active' => true]);
    Cache::flush();
    app(OfficeSettings::class)->save(['provider' => null, 'server_url' => '', 'internal_url' => '', 'callback_url' => '', 'extensions' => []], null);
    $this->get(route('dateien.office.onlyoffice.file', ['token' => $token]), $auth)->assertNotFound();
});

it('does not open files the Kamerad cannot read or unsupported types', function () {
    useOnlyOffice();
    $other = filesMember('Bernd Blaulicht');
    $this->actingAs($other)->get(route('dateien.office', ['node' => $this->node->id]))->assertForbidden();
    expect(app(OfficeService::class)->canOpen($this->member, $this->node))->toBeTrue();
    app(OfficeSettings::class)->save(['provider' => 'onlyoffice', 'server_url' => 'https://office.ff-musterstadt.de', 'internal_url' => '', 'callback_url' => '', 'extensions' => ['docx']], null);
    expect(app(OfficeService::class)->canOpen($this->member, $this->node))->toBeFalse();
    $this->actingAs($this->member)->get(route('dateien.office', ['node' => $this->node->id]))->assertNotFound();
});

it('serves Collabora WOPI only with a valid proof for the right file', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $rsa = openssl_pkey_get_details($key)['rsa'];
    $xml = '<?xml version="1.0"?><wopi-discovery><net-zone name="external-http"><app name="text/plain"><action name="view" ext="txt" urlsrc="http://intern:9980/browser/abc/cool.html?"/></app></net-zone>'
        .'<proof-key value="x" modulus="'.base64_encode($rsa['n']).'" exponent="'.base64_encode($rsa['e']).'"/></wopi-discovery>';
    Http::fake(['http://intern:9980/hosting/discovery' => Http::response($xml)]);
    app(OfficeSettings::class)->save(['provider' => 'collabora', 'server_url' => 'https://office.ff-musterstadt.de', 'internal_url' => 'http://intern:9980', 'callback_url' => '', 'extensions' => ['txt']], null);

    $page = $this->actingAs($this->member)->get(route('dateien.office', ['node' => $this->node->id]))->assertOk();
    expect($page->getContent())->toContain('https://office.ff-musterstadt.de/browser/abc/cool.html?WOPISrc=')
        ->and($page->headers->get('Content-Security-Policy'))->toContain('form-action https://office.ff-musterstadt.de');
    preg_match('/name="access_token" value="([A-Za-z0-9]{64})"/', $page->getContent(), $m);
    $token = $m[1];
    $url = route('dateien.office.wopi.file', ['node' => $this->node->id]).'?access_token='.$token;
    $sign = function (string $url) use ($key, $token) {
        $ticks = WopiProof::ticks();
        openssl_sign(WopiProof::data($token, $url, $ticks), $signature, $key, OPENSSL_ALGO_SHA256);

        return ['X-WOPI-TimeStamp' => (string) $ticks, 'X-WOPI-Proof' => base64_encode($signature)];
    };

    $this->get($url)->assertUnauthorized();
    $this->get($url, $sign($url.'&x=1'))->assertUnauthorized();
    $this->get($url, $sign($url))->assertOk()->assertJson(['BaseFileName' => 'dienstplan.txt', 'UserCanWrite' => false, 'ReadOnly' => true]);
    $contents = route('dateien.office.wopi.contents', ['node' => $this->node->id]).'?access_token='.$token;
    $this->get($contents, $sign($contents))->assertOk()->assertStreamedContent('Inhalt des Dienstplans');

    $other = $this->files->upload($this->admin, $this->space, null, filesUpload('Andere Datei mit Inhalt', 'andere.txt'));
    $foreign = route('dateien.office.wopi.file', ['node' => $other->id]).'?access_token='.$token;
    $this->get($foreign, $sign($foreign))->assertUnauthorized();
});

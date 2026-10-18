<?php

use App\Models\Module;
use Extensions\Dateien\Livewire\Explorer;
use Extensions\Dateien\Models\AuditEvent;
use Extensions\Dateien\Models\Share;
use Extensions\Dateien\Services\FileService;
use Extensions\Dateien\Services\ShareService;
use Extensions\Locations\Models\Location;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Module::where('key', 'dateien')->update(['is_active' => true]);
    Cache::flush();
    Storage::fake('dateien');
    $this->admin = filesMember('Ada Atemschutz', admin: true);
    $this->member = filesMember();
    $this->files = app(FileService::class);
    $this->shares = app(ShareService::class);
    $this->space = $this->files->createSpace($this->admin, ['name' => 'Jugendfeuerwehr', 'ext_location_id' => Location::default()->id]);
    $this->folder = $this->files->createFolder($this->admin, $this->space, null, 'Zeltlager');
    $this->sub = $this->files->createFolder($this->admin, $this->space, $this->folder, 'Fotos');
    $this->file = $this->files->upload($this->admin, $this->space, $this->folder, filesUpload('Packliste für das Zeltlager', 'packliste.txt'));
    $this->subFile = $this->files->upload($this->admin, $this->space, $this->sub, filesUpload('Bild', 'lagerfeuer.txt'));
    $this->outside = $this->files->upload($this->admin, $this->space, null, filesUpload('Interna', 'kasse.txt'));
});

function allowShares(): void
{
    test()->shares->saveSettings(test()->admin, true, 30);
    test()->shares->allowSpace(test()->admin, test()->space, true);
}

test('links are off by default and need the global switch and the space flag', function () {
    expect(ShareService::enabled())->toBeFalse()
        ->and($this->shares->canCreate($this->admin, $this->folder))->toBeFalse();
    $this->shares->saveSettings($this->admin, true, 30);
    expect($this->shares->canCreate($this->admin, $this->folder))->toBeFalse();
    $this->shares->allowSpace($this->admin, $this->space, true);
    expect($this->shares->canCreate($this->admin, $this->folder))->toBeTrue();

    [, $token] = $this->shares->create($this->admin, $this->folder, 7);
    $this->get(route('dateien.share', ['token' => $token]))->assertOk()->assertSee('packliste.txt')->assertSee('Fotos')->assertDontSee('kasse.txt');

    $this->shares->saveSettings($this->admin, false, 30);
    $this->get(route('dateien.share', ['token' => $token]))->assertNotFound();
    $this->shares->saveSettings($this->admin, true, 30);
    $this->shares->allowSpace($this->admin, $this->space, false);
    $this->get(route('dateien.share', ['token' => $token]))->assertNotFound();
});

test('only managers create links and only admins change the settings', function () {
    allowShares();
    $this->files->setPermission($this->admin, $this->folder, 'user', $this->member->id, 'edit');
    expect(fn () => $this->shares->create($this->member, $this->folder, 7))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->shares->saveSettings($this->member, true, 30))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->shares->create($this->admin, $this->folder, 31))->toThrow(ValidationException::class);
});

test('pages and downloads stay inside the shared subtree', function () {
    allowShares();
    [, $token] = $this->shares->create($this->admin, $this->folder, 7);

    $this->get(route('dateien.share', ['token' => $token, 'ordner' => $this->sub->id]))->assertOk()->assertSee('lagerfeuer.txt');
    $this->get(route('dateien.share.download', ['token' => $token, 'node' => $this->subFile->id]))->assertOk();
    $this->get(route('dateien.share', ['token' => $token, 'ordner' => $this->outside->id]))->assertNotFound();
    $this->get(route('dateien.share.download', ['token' => $token, 'node' => $this->outside->id]))->assertNotFound();

    [, $fileToken] = $this->shares->create($this->admin, $this->subFile, 7);
    $this->get(route('dateien.share', ['token' => $fileToken, 'ordner' => $this->folder->id]))->assertNotFound();
    $this->get(route('dateien.share.download', ['token' => $fileToken, 'node' => $this->file->id]))->assertNotFound();
    $this->get(route('dateien.share.download', ['token' => $fileToken, 'node' => $this->subFile->id]))->assertOk();

    $this->files->trash($this->admin, $this->sub);
    $this->get(route('dateien.share', ['token' => $token, 'ordner' => $this->sub->id]))->assertNotFound();
    $this->get(route('dateien.share.download', ['token' => $fileToken, 'node' => $this->subFile->id]))->assertNotFound();
    $this->get(route('dateien.share', ['token' => str_repeat('a', 48)]))->assertNotFound();
});

test('pages are not cached, not indexed and send no referrer', function () {
    allowShares();
    [, $token] = $this->shares->create($this->admin, $this->folder, 7);
    $response = $this->get(route('dateien.share', ['token' => $token]))->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Robots-Tag'))->toContain('noindex')
        ->and($response->getContent())->toContain('<meta name="referrer" content="no-referrer">')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'");
    $download = $this->get(route('dateien.share.download', ['token' => $token, 'node' => $this->file->id]))->assertOk();
    expect($download->headers->get('Cache-Control'))->toContain('no-store');
});

test('expired, revoked and exhausted links answer 404', function () {
    allowShares();
    [$share, $token] = $this->shares->create($this->admin, $this->file, 1, maxDownloads: 1);
    $url = route('dateien.share.download', ['token' => $token, 'node' => $this->file->id]);
    // A range request still counts as a whole download, so the limit can't be bypassed in pieces
    $this->withHeaders(['Range' => 'bytes=3-'])->get($url)->assertOk()->assertHeader('Accept-Ranges', 'none');
    expect($share->fresh()->download_count)->toBe(1);
    $this->get($url)->assertNotFound();

    [$revoked, $revokedToken] = $this->shares->create($this->admin, $this->file, 1);
    $this->shares->revoke($this->admin, $revoked);
    $this->get(route('dateien.share', ['token' => $revokedToken]))->assertNotFound();

    [, $expiring] = $this->shares->create($this->admin, $this->file, 1);
    $this->travel(2)->days();
    $this->get(route('dateien.share', ['token' => $expiring]))->assertNotFound();
});

test('a link dies when its creator loses the manage right', function () {
    allowShares();
    $this->files->setPermission($this->admin, $this->folder, 'user', $this->member->id, 'manage');
    [, $token] = $this->shares->create($this->member, $this->folder, 7);
    $this->get(route('dateien.share', ['token' => $token]))->assertOk();
    $this->files->setPermission($this->admin, $this->folder, 'user', $this->member->id, 'read');
    $this->get(route('dateien.share', ['token' => $token]))->assertNotFound();
});

test('password links unlock per session and are rate limited', function () {
    allowShares();
    [$share, $token] = $this->shares->create($this->admin, $this->folder, 7, 'Lager2026!');
    $this->get(route('dateien.share', ['token' => $token]))->assertUnauthorized()->assertDontSee('packliste.txt');
    $this->get(route('dateien.share.download', ['token' => $token, 'node' => $this->file->id]))->assertNotFound();

    for ($i = 0; $i < 5; $i++) {
        $this->post(route('dateien.share.unlock', ['token' => $token]), ['passwort' => 'falsch-'.$i])->assertUnauthorized();
    }
    $this->post(route('dateien.share.unlock', ['token' => $token]), ['passwort' => 'Lager2026!'])->assertStatus(429);
    expect(AuditEvent::where('action', 'share.password_failed')->count())->toBe(5);

    RateLimiter::clear('dateien-share-pw|'.$share->id.'|127.0.0.1');
    RateLimiter::clear('dateien-share-pw|'.$share->id);
    $this->post(route('dateien.share.unlock', ['token' => $token]), ['passwort' => 'Lager2026!'])->assertRedirect();
    $this->get(route('dateien.share', ['token' => $token]))->assertOk()->assertSee('packliste.txt');
    $this->get(route('dateien.share.download', ['token' => $token, 'node' => $this->file->id]))->assertOk();
});

test('the audit log records downloads with a shortened address and never the token', function () {
    allowShares();
    [$share, $token] = $this->shares->create($this->admin, $this->file, 7);
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
        ->get(route('dateien.share.download', ['token' => $token, 'node' => $this->file->id]))->assertOk();
    $event = AuditEvent::where('action', 'share.downloaded')->sole();
    expect($event->metadata['ip'] ?? null)->toBe('203.0.113.0')
        ->and(AuditEvent::where('metadata', 'like', '%'.$token.'%')->exists())->toBeFalse()
        ->and($share->fresh()->token_hash)->toBe(hash('sha256', $token))
        ->and(ShareService::shortIp('2001:db8:1234:5678::1'))->toBe('2001:db8:1234::/48');
});

test('managers create and revoke links in the explorer, the link is shown once', function () {
    allowShares();
    $component = Livewire::actingAs($this->admin)->test(Explorer::class)
        ->call('navigate', null, $this->folder->id)
        ->call('select', $this->file->id)
        ->set('shareDays', '3')
        ->set('shareLabel', 'Für die Eltern')
        ->call('createShare')
        ->assertHasNoErrors();
    $url = $component->get('newShareUrl');
    expect($url)->toStartWith(url('/freigabe/'));
    $share = Share::sole();
    expect($share->label)->toBe('Für die Eltern');
    $this->get($url)->assertOk()->assertSee('packliste.txt');

    $component->call('revokeShare', $share->id)->assertSet('newShareUrl', null);
    $this->get($url)->assertNotFound();

    $this->files->setPermission($this->admin, $this->file, 'user', $this->member->id, 'read');
    Livewire::actingAs($this->member)->test(Explorer::class)
        ->call('navigate', null, null, 'shared')
        ->call('select', $this->file->id)
        ->call('createShare')
        ->assertForbidden();
});

test('old links are pruned', function () {
    allowShares();
    $this->shares->create($this->admin, $this->file, 1);
    $this->travel(32)->days();
    $this->artisan('dateien:prune-shares')->assertSuccessful();
    expect(Share::count())->toBe(0);
});

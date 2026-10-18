<?php

use App\Models\Module;
use App\Services\DashboardRegistry;
use Extensions\Dateien\Livewire\Explorer;
use Extensions\Dateien\Models\AuditEvent;
use Extensions\Dateien\Models\Comment;
use Extensions\Dateien\Notifications\FileActivityNotification;
use Extensions\Dateien\Services\CollaborationService;
use Extensions\Dateien\Services\FileService;
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
    Module::where('key', 'dateien')->update(['is_active' => true]);
    Cache::flush();
    Storage::fake('dateien');
    $this->admin = filesMember('Ada Atemschutz', admin: true);
    $this->member = filesMember();
    $this->files = app(FileService::class);
    $this->collab = app(CollaborationService::class);
    $this->space = $this->files->createSpace($this->admin, ['name' => 'Ausbildung', 'ext_location_id' => Location::default()->id]);
    $this->folder = $this->files->createFolder($this->admin, $this->space, null, 'Unterlagen');
    $this->secret = $this->files->createFolder($this->admin, $this->space, $this->folder, 'Prüfungen');
    $this->file = $this->files->upload($this->admin, $this->space, $this->folder, filesUpload('Knoten und Stiche', 'knoten.txt'));
    $this->hidden = $this->files->upload($this->admin, $this->space, $this->secret, filesUpload('Lösungen', 'loesungen.txt'));
    $this->files->setPermission($this->admin, $this->folder, 'user', $this->member->id, 'read');
    $this->files->setInheritance($this->admin, $this->secret, false);
});

test('favourites and recently used only list what is still readable', function () {
    expect($this->collab->toggleFavorite($this->member, $this->file))->toBeTrue()
        ->and(fn () => $this->collab->toggleFavorite($this->member, $this->hidden))->toThrow(AuthorizationException::class);
    $this->actingAs($this->member)->get(route('dateien.download', ['node' => $this->file->id, 'version' => $this->file->versions()->first()->id]))->assertOk();
    expect($this->collab->favorites($this->member)->pluck('id')->all())->toBe([$this->file->id])
        ->and($this->collab->recent($this->member)->pluck('id')->all())->toBe([$this->file->id]);

    $cards = app(DashboardRegistry::class)->for($this->member);
    expect(implode('', $cards))->toContain('knoten.txt');

    $this->files->setPermission($this->admin, $this->folder, 'user', $this->member->id, null);
    expect($this->collab->favorites($this->member))->toBeEmpty()
        ->and($this->collab->recent($this->member))->toBeEmpty();
    expect($this->collab->toggleFavorite($this->admin, $this->file))->toBeTrue()
        ->and($this->collab->toggleFavorite($this->admin, $this->file))->toBeFalse();
});

test('subscribers of a folder are notified about new files, versions and comments, never without read access', function () {
    Notification::fake();
    $outsider = filesMember('Bernd Blaulicht');
    $this->files->setPermission($this->admin, $this->space, 'user', $outsider->id, 'read');
    $this->collab->toggleSubscription($this->member, $this->folder);
    $this->collab->toggleSubscription($outsider, $this->folder);
    $this->files->setPermission($this->admin, $this->secret, 'user', $outsider->id, 'read');

    $this->files->upload($this->admin, $this->space, $this->folder, filesUpload('Neu', 'leinen.txt'));
    Notification::assertSentTo($this->member, FileActivityNotification::class);
    Notification::assertSentTo($outsider, FileActivityNotification::class);

    // The member can't read "Prüfungen": no mail about what happens inside
    Cache::flush();
    Notification::fake();
    $this->files->addVersion($this->admin, $this->hidden, filesUpload('Neue Lösungen', 'loesungen.txt'));
    Notification::assertNotSentTo($this->member, FileActivityNotification::class);
    Notification::assertSentTo($outsider, FileActivityNotification::class);

    // The actor gets nothing; a second change within the quiet time sends no second mail
    Cache::flush();
    Notification::fake();
    $this->collab->comment($this->member, $this->file, 'Bitte Seite 2 ergänzen.');
    Notification::assertNotSentTo($this->member, FileActivityNotification::class);
    Notification::assertSentToTimes($outsider, FileActivityNotification::class, 1);
    $this->collab->comment($this->member, $this->file, 'Und Seite 3.');
    Notification::assertSentToTimes($outsider, FileActivityNotification::class, 1);
});

test('comments need read access and are deleted only by the author or a manager', function () {
    $comment = $this->collab->comment($this->member, $this->file, 'Gute Übersicht.');
    expect(fn () => $this->collab->comment($this->member, $this->hidden, 'Hallo'))->toThrow(AuthorizationException::class)
        ->and($this->collab->comments($this->admin, $this->file))->toHaveCount(1);
    $other = $this->collab->comment($this->admin, $this->file, 'Danke.');
    expect(fn () => $this->collab->deleteComment($this->member, $other))->toThrow(AuthorizationException::class);
    $this->collab->deleteComment($this->member, $comment);
    $this->collab->deleteComment($this->admin, $other);
    expect(Comment::count())->toBe(0)
        ->and(AuditEvent::where('action', 'comment.deleted')->count())->toBe(2);
});

test('the description is editable with edit rights and found by the full-text search', function () {
    expect(fn () => $this->files->describe($this->member, $this->file, 'Für die Grundausbildung'))->toThrow(AuthorizationException::class);
    $this->files->describe($this->admin, $this->file, 'Unterlagen für die Grundausbildung zu Knoten und Stichen');
    $this->files->describe($this->admin, $this->hidden, 'Grundausbildung Prüfung');

    expect($this->files->search($this->member, 'Grundausbildungen')->pluck('id')->all())->toBe([$this->file->id])
        ->and($this->files->search($this->admin, 'Grundausbildung')->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->file->id, $this->hidden->id])->sort()->values()->all());
});

test('folders download as ZIP with only the readable entries', function () {
    $response = $this->actingAs($this->member)->get(route('dateien.zip', ['node' => $this->folder->id]))->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/zip');
    $zip = $response->streamedContent();
    expect($zip)->toStartWith("PK\x03\x04")
        ->toContain('knoten.txt')->toContain('Knoten und Stiche')
        ->not->toContain('Prüfungen')->not->toContain('Lösungen');

    $path = tempnam(sys_get_temp_dir(), 'zip');
    file_put_contents($path, $zip);
    if (class_exists(ZipArchive::class)) {
        $archive = new ZipArchive;
        expect($archive->open($path, ZipArchive::CHECKCONS))->toBeTrue()
            ->and($archive->getFromName('knoten.txt'))->toBe('Knoten und Stiche');
        $archive->close();
    }
    unlink($path);

    $this->actingAs($this->member)->get(route('dateien.zip', ['node' => $this->secret->id]))->assertForbidden();
    config(['dateien.zip.max_entries' => 0]);
    $this->actingAs($this->admin)->get(route('dateien.zip', ['node' => $this->folder->id]))->assertRedirect()->assertSessionHasErrors('zip');
});

test('image previews are made on the server, cached and need read access', function () {
    $image = imagecreatetruecolor(1200, 800);
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();
    $photo = $this->files->upload($this->admin, $this->space, $this->folder, UploadedFile::fake()->createWithContent('uebung.png', $png));
    $hiddenPhoto = $this->files->upload($this->admin, $this->space, $this->secret, UploadedFile::fake()->createWithContent('geheim.png', $png));

    $response = $this->actingAs($this->member)->get(route('dateien.preview', ['node' => $photo->id]))->assertOk();
    expect($response->headers->get('Content-Type'))->toBeIn(['image/webp', 'image/png'])
        ->and($response->headers->get('Cache-Control'))->toContain('private');
    $size = getimagesizefromstring($response->getContent());
    expect(max($size[0], $size[1]))->toBe(480)
        ->and(DB::table('x_files_previews')->count())->toBe(1);

    $this->actingAs($this->member)->get(route('dateien.preview', ['node' => $hiddenPhoto->id]))->assertForbidden();
    $this->actingAs($this->member)->get(route('dateien.preview', ['node' => $this->file->id]))->assertNotFound();

    config(['dateien.previews.max_pixels' => 1000]);
    $big = $this->files->upload($this->admin, $this->space, $this->folder, UploadedFile::fake()->createWithContent('gross.png', $png));
    $this->actingAs($this->admin)->get(route('dateien.preview', ['node' => $big->id]))->assertNotFound();
});

test('the explorer offers favourites, subscriptions, description and comments', function () {
    Livewire::actingAs($this->admin)->test(Explorer::class)
        ->call('navigate', null, $this->folder->id)
        ->call('toggleFavorite')
        ->call('select', $this->file->id)
        ->call('toggleSubscription')
        ->set('description', 'Merkblatt')
        ->call('saveDescription')
        ->set('commentBody', 'Bitte aktualisieren.')
        ->call('addComment')
        ->assertHasNoErrors()
        ->assertSee('Bitte aktualisieren.')
        ->call('navigate', null, null, 'favorites')
        ->assertSee('Unterlagen');
    expect($this->file->fresh()->description)->toBe('Merkblatt')
        ->and($this->collab->isSubscribed($this->admin, $this->file))->toBeTrue();
});

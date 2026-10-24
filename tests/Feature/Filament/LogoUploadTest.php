<?php

use App\Filament\Pages\OrganizationSettingsPage;
use App\Models\User;
use App\Services\OrganizationSettings;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

function uploadLogo(UploadedFile $file)
{
    return Livewire::actingAs(User::factory()->admin()->create())->test(OrganizationSettingsPage::class)
        ->set('logo', [$file])->call('save');
}

test('raster logos are normalized to WebP and safely served', function (UploadedFile $file) {
    uploadLogo($file)->assertHasNoErrors();

    $org = app(OrganizationSettings::class);
    expect($org->logoMime())->toBe('image/webp')->and($org->logoUrl())->not->toBeNull()
        ->and(getimagesizefromstring(base64_decode($org->logoData()))[0])->toBe(200)
        ->and(getimagesizefromstring(base64_decode($org->logoData()))[1])->toBe(200);

    $this->get($org->logoUrl())->assertOk()->assertHeader('Content-Type', 'image/webp')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'");
})->with([
    'PNG' => fn () => UploadedFile::fake()->image('logo.png', 200, 200),
    'JPEG' => fn () => UploadedFile::fake()->image('logo.jpg', 200, 200),
    'WebP' => fn () => UploadedFile::fake()->image('logo.webp', 200, 200),
]);

test('a new logo replaces the old one; saving without a file keeps it', function () {
    uploadLogo(UploadedFile::fake()->image('alt.png', 100, 100));
    uploadLogo(UploadedFile::fake()->image('neu.webp', 100, 100));
    expect(app(OrganizationSettings::class)->logoMime())->toBe('image/webp');

    Livewire::actingAs(User::factory()->admin()->create())->test(OrganizationSettingsPage::class)->set('org_name', 'FF Musterstadt')->call('save');
    expect(app(OrganizationSettings::class)->logoMime())->toBe('image/webp');
});

test('other files and too large images are refused', function () {
    uploadLogo(UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'))->assertHasErrors('logo');
    uploadLogo(UploadedFile::fake()->image('riesig.png')->size(3000))->assertHasErrors('logo');
    expect(app(OrganizationSettings::class)->logoUrl())->toBeNull();
});

test('SVG, disguised files and oversized dimensions are refused without changing settings', function () {
    $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    uploadLogo($svg)->assertHasErrors('logo');
    uploadLogo(UploadedFile::fake()->createWithContent('fake.png', '<?php echo "not an image";'))->assertHasErrors('logo');
    uploadLogo(UploadedFile::fake()->image('too-wide.png', 4097, 10))->assertHasErrors('logo');
    expect(app(OrganizationSettings::class)->logoData())->toBeNull();
});

test('valid image uploads lose appended content and executable filename extensions are refused', function () {
    $image = UploadedFile::fake()->image('original.png', 40, 40);
    $bytes = file_get_contents($image->getRealPath());
    uploadLogo(UploadedFile::fake()->createWithContent('logo.php', $bytes))->assertHasErrors('logo');
    expect(app(OrganizationSettings::class)->logoData())->toBeNull();

    uploadLogo(UploadedFile::fake()->createWithContent('logo.png', $bytes.'<?php /* upload-marker */'))
        ->assertHasNoErrors();
    $stored = base64_decode(app(OrganizationSettings::class)->logoData());
    expect($stored)->not->toContain('<?php', 'upload-marker')
        ->and(getimagesizefromstring($stored)['mime'])->toBe('image/webp');
});

test('replacement invalidates the logo URL even within the same second and deletion removes the bytes', function () {
    uploadLogo(UploadedFile::fake()->image('first.png', 20, 20));
    $org = app(OrganizationSettings::class);
    $oldUrl = $org->logoUrl();
    uploadLogo(UploadedFile::fake()->image('second.png', 30, 30));
    expect($org->logoUrl())->not->toBe($oldUrl);
    $this->get($oldUrl)->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    Livewire::actingAs(User::factory()->admin()->create())->test(OrganizationSettingsPage::class)->call('deleteLogo');
    expect($org->logoData())->toBeNull()->and($org->logoMime())->toBeNull()->and($org->logoUrl())->toBeNull();
    $this->get(route('organization.logo'))->assertNotFound();
});

test('only active global admins can open or mutate organization settings', function () {
    $this->get('/admin/einstellungen')->assertRedirect();
    $member = User::factory()->create();
    Livewire::actingAs($member)->test(OrganizationSettingsPage::class)->assertForbidden();
    $this->actingAs($member)->get('/admin/einstellungen')->assertForbidden();

    $admin = User::factory()->admin()->create();
    $page = Livewire::actingAs($admin)->test(OrganizationSettingsPage::class);
    $admin->update(['is_admin' => false]);
    $page->call('deleteLogo')->assertForbidden();
});

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

test('a PNG, WebP or SVG logo is saved and served', function (UploadedFile $file, string $mime) {
    uploadLogo($file)->assertHasNoErrors();

    $org = app(OrganizationSettings::class);
    expect($org->logoMime())->toBe($mime)->and($org->logoUrl())->not->toBeNull()
        ->and(base64_decode($org->logoData()))->toBe(file_get_contents($file->getRealPath()));

    $this->get($org->logoUrl())->assertOk()->assertHeader('Content-Type', $mime)
        ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox");
})->with([
    'PNG' => fn () => [UploadedFile::fake()->image('logo.png', 200, 200), 'image/png'],
    'WebP' => fn () => [UploadedFile::fake()->image('logo.webp', 200, 200), 'image/webp'],
    'SVG' => fn () => [UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4" fill="red"/></svg>'), 'image/svg+xml'],
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

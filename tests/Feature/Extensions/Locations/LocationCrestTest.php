<?php

use App\Models\ActivityLog;
use App\Models\User;
use Extensions\Locations\Filament\Resources\LocationResource\Pages\CreateLocation;
use Extensions\Locations\Filament\Resources\LocationResource\Pages\EditLocation;
use Extensions\Locations\Models\Location;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

test('global admins can create a Standort with a crest', function () {
    Livewire::actingAs(User::factory()->admin()->create())->test(CreateLocation::class)
        ->fillForm(['name' => 'Wache Nord', 'slug' => 'nord', 'is_active' => true])
        ->set('data.crest_upload', [UploadedFile::fake()->image('nord.png', 50, 50)])
        ->call('create')->assertHasNoFormErrors();

    $location = Location::where('slug', 'nord')->sole();
    expect($location->crest_data)->not->toBeNull()
        ->and(getimagesizefromstring(base64_decode($location->crest_data))['mime'])->toBe('image/webp');
});

test('Standort admins can upload, replace, retain and remove only their own crest', function () {
    $location = Location::create(['name' => 'Wache Nord', 'slug' => 'nord']);
    $other = Location::create(['name' => 'Wache Süd', 'slug' => 'sued']);
    $admin = User::factory()->create(['ext_location_id' => $location->id]);
    $location->members()->attach($admin, ['role' => 'admin']);

    $page = Livewire::actingAs($admin)->test(EditLocation::class, ['record' => $location->id])
        ->set('data.crest_upload', [UploadedFile::fake()->image('nord.png', 50, 50)])
        ->call('save')->assertHasNoFormErrors();
    $firstUrl = $location->fresh()->crestUrl();
    expect($firstUrl)->not->toBeNull();
    $this->get($firstUrl)->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('Cache-Control', 'no-store, private');
    $this->get('/')->assertOk()->assertSee($firstUrl);
    $this->get('/admin/standorte')->assertOk()->assertSee($firstUrl);

    $page->set('data.crest_upload', [UploadedFile::fake()->image('neu.jpg', 60, 60)])
        ->call('save')->assertHasNoFormErrors();
    $newData = $location->fresh()->crest_data;
    expect($location->fresh()->crestUrl())->not->toBe($firstUrl);
    $page->set('data.crest_upload', [])->call('save')->assertHasNoFormErrors();
    expect($location->fresh()->crest_data)->toBe($newData);

    Livewire::actingAs($admin)->test(EditLocation::class, ['record' => $other->id])->assertNotFound();
    $page->set('data.remove_crest', true)->call('save')->assertHasNoFormErrors();
    expect($location->fresh()->crest_data)->toBeNull()->and($location->fresh()->crestUrl())->toBeNull();
    $this->get($firstUrl)->assertNotFound();
    expect(ActivityLog::where('subject_type', Location::class)->pluck('changes')->toJson())->not->toContain($newData);
});

test('members may view only their own Standort crests and may not edit them', function () {
    $location = Location::create(['name' => 'Wache Nord', 'slug' => 'nord', 'crest_data' => base64_encode('image')]);
    $other = Location::create(['name' => 'Wache Süd', 'slug' => 'sued', 'crest_data' => base64_encode('image')]);
    $member = User::factory()->create(['ext_location_id' => $location->id]);
    $url = $location->crestUrl();
    $this->get($url)->assertRedirect();
    $this->actingAs($member)->get($url)->assertOk();
    $this->get($other->crestUrl())->assertForbidden();
    Livewire::actingAs($member)->test(EditLocation::class, ['record' => $location->id])->assertNotFound();
    $member->forceFill(['ext_location_id' => null])->save();
    $this->get($url)->assertForbidden();
});

test('invalid crest uploads are refused without replacing existing image data', function () {
    $location = Location::create(['name' => 'Wache Nord', 'slug' => 'nord', 'crest_data' => 'existing']);
    $page = Livewire::actingAs(User::factory()->admin()->create())->test(EditLocation::class, ['record' => $location->id]);
    foreach ([
        UploadedFile::fake()->createWithContent('bad.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        UploadedFile::fake()->create('large.png', 3000, 'image/png'),
        UploadedFile::fake()->image('wide.png', 4097, 2),
        UploadedFile::fake()->createWithContent('fake.png', '<?php echo "invalid";'),
    ] as $file) {
        $page->set('data.crest_upload', [$file])->call('save')->assertHasErrors();
        expect($location->fresh()->crest_data)->toBe('existing');
    }
});

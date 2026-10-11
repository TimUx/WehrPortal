<?php

use App\Livewire\Profile\AvatarUpload;
use App\Models\Group;
use App\Models\User;
use App\Services\AvatarService;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Fahrzeugbuchung\src\Livewire\Booking\BookingForm;

test('user uploads an avatar, it is cropped to 256px and served', function () {
    $user = User::factory()->create(['name' => 'Hanna Hydrant']);

    Livewire::actingAs($user)
        ->test(AvatarUpload::class)
        ->set('photo', UploadedFile::fake()->image('ich.jpg', 800, 600))
        ->assertHasNoErrors()
        ->assertRedirect(route('profile'));

    $user->refresh();
    expect($user->avatar_updated_at)->not->toBeNull()
        ->and($user->avatar->mime)->toBe('image/webp');

    [$width, $height] = getimagesizefromstring(base64_decode($user->avatar->data));
    expect([$width, $height])->toBe([256, 256]);

    $this->actingAs($user)->get($user->avatarUrl())
        ->assertOk()
        ->assertHeader('Content-Type', 'image/webp');

    $this->actingAs($user)->get(route('profile'))->assertSee($user->avatarUrl(), false);
});

test('non-images are rejected and initials are shown without avatar', function () {
    $user = User::factory()->create(['name' => 'Hanna Hydrant']);

    Livewire::actingAs($user)
        ->test(AvatarUpload::class)
        ->set('photo', UploadedFile::fake()->create('lebenslauf.pdf', 100, 'application/pdf'))
        ->assertHasErrors('photo');

    expect($user->fresh()->avatar_updated_at)->toBeNull()
        ->and($user->initials())->toBe('HH');

    $this->actingAs($user)->get(route('profile'))->assertSee('HH');
});

test('avatar can be removed', function () {
    $user = User::factory()->create();
    app(AvatarService::class)->store($user, UploadedFile::fake()->image('a.png', 300, 300));

    Livewire::actingAs($user)->test(AvatarUpload::class)->call('remove');

    expect($user->fresh()->avatar_updated_at)->toBeNull()
        ->and($user->fresh()->avatar)->toBeNull();
    $this->actingAs($user)->get(route('avatars.show', $user))->assertNotFound();
});

test('booking form offers all groups, not only the booker\'s own', function () {
    $user = User::factory()->create();
    $own = Group::factory()->create(['name' => 'Aktiver Dienst']);
    Group::factory()->create(['name' => 'Musikzug']);
    $user->groups()->attach($own);

    Livewire::actingAs($user)
        ->test(BookingForm::class)
        ->call('open')
        ->assertSee('Aktiver Dienst')
        ->assertSee('Musikzug');
});

<?php

use App\Models\User;
use Extensions\Locations\Models\Location;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

function filesMember(string $name = 'Hanna Hydrant', ?Location $location = null, bool $admin = false): User
{
    $user = User::factory()->create([
        'name' => $name,
        'email' => 'dateien-'.Str::uuid().'@example.com',
        'is_admin' => $admin,
    ]);
    $user->moduleAccess()->create(['module' => 'dateien']);
    $location ??= Location::default();
    $user->forceFill(['ext_location_id' => $location->id])->save();
    $user->locations()->sync([$location->id => ['role' => 'member']]);

    return $user;
}

function filesUpload(string $contents = 'Erste Fassung', string $name = 'dienstplan.txt'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $contents);
}

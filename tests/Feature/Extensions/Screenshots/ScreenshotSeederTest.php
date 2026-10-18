<?php

use App\Models\Module;
use App\Models\User;
use Database\Seeders\ScreenshotSeeder;
use Extensions\Anwesenheit\Models\AttendanceList;
use Extensions\Hydrantenkontrolle\Models\Hydrant;
use Modules\Fahrzeugbuchung\src\Models\Booking;

it('builds the example installation for the screenshots on a fresh database', function () {
    $this->seed(ScreenshotSeeder::class);

    $admin = User::where('email', 'admin@example.com')->firstOrFail();
    expect($admin->is_admin)->toBeTrue()
        ->and($admin->is_active)->toBeTrue();

    foreach (ScreenshotSeeder::MODULES as $key) {
        expect(Module::find($key)?->is_active)->toBeTrue();
        expect($admin->canUseModule($key))->toBeTrue();
    }

    expect(Booking::active()->count())->toBe(4)
        ->and(Booking::active()->where('user_id', $admin->id)->where('starts_at', '>', now())->exists())->toBeTrue()
        ->and(Hydrant::count())->toBe(3)
        ->and(AttendanceList::where('status', AttendanceList::SUBMITTED)->first()?->participants()->count())->toBe(2);
});

it('can run twice without duplicating data', function () {
    $this->seed(ScreenshotSeeder::class);
    $this->seed(ScreenshotSeeder::class);

    expect(Booking::count())->toBe(4)
        ->and(Hydrant::count())->toBe(3)
        ->and(AttendanceList::count())->toBe(1);
});

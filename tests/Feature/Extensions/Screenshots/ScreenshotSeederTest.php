<?php

use App\Models\Module;
use App\Models\User;
use Database\Seeders\ScreenshotSeeder;
use Extensions\Anwesenheit\Models\AttendanceList;
use Extensions\Hydrantenkontrolle\Models\Hydrant;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Maengelmanagement\src\Models\Defect;
use Modules\Mitteilungen\src\Models\Announcement;
use Modules\Verbandbuch\src\Models\Entry as FirstAidEntry;
use Modules\Wasserkarte\src\Models\Source;

/** Row counts of a few modules: the same after every run of the seeder */
function screenshotCounts(): array
{
    return [
        'bookings' => Booking::count(),
        'hydrants' => Hydrant::count(),
        'attendance' => AttendanceList::count(),
        'announcements' => Announcement::count(),
        'defects' => Defect::count(),
        'reports' => Report::count(),
        'first_aid' => FirstAidEntry::count(),
        'water' => Source::count(),
        'users' => User::count(),
    ];
}

it('builds the example installation for the screenshots on a fresh database', function () {
    $this->seed(ScreenshotSeeder::class);

    $admin = User::where('email', 'admin@example.com')->firstOrFail();
    expect($admin->is_admin)->toBeTrue()
        ->and($admin->is_active)->toBeTrue();

    expect(ScreenshotSeeder::modules())->toContain('fahrzeugbuchung', 'hydrantenkontrolle', 'anwesenheit', 'wasserkarte');
    foreach (ScreenshotSeeder::modules() as $key) {
        expect(Module::find($key)?->is_active)->toBeTrue("module {$key} is active");
        expect($admin->canUseModule($key))->toBeTrue("admin can use {$key}");
    }

    expect(Booking::active()->count())->toBe(4)
        ->and(Booking::active()->where('user_id', $admin->id)->where('starts_at', '>', now())->exists())->toBeTrue()
        ->and(Hydrant::count())->toBe(3)
        ->and(AttendanceList::where('status', AttendanceList::SUBMITTED)->first()?->participants()->count())->toBe(2)
        ->and(Announcement::count())->toBeGreaterThan(0)
        ->and(Defect::count())->toBeGreaterThan(0)
        ->and(Report::count())->toBeGreaterThan(0)
        ->and(FirstAidEntry::count())->toBeGreaterThan(0)
        ->and(Source::count())->toBe(3);
});

it('can run twice without duplicating data', function () {
    $this->seed(ScreenshotSeeder::class);
    $first = screenshotCounts();

    $this->seed(ScreenshotSeeder::class);

    expect(screenshotCounts())->toBe($first)
        ->and($first['bookings'])->toBe(4)
        ->and($first['attendance'])->toBe(1);
});

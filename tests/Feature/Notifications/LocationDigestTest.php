<?php

use App\Models\Module;
use App\Models\User;
use App\Services\LocationNotifications;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Models\StandardRoleAssignment;
use Extensions\Locations\Services\StandardRoleAssignments;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Modules\Atemschutz\src\Models\Wearer;
use Modules\Atemschutz\src\Notifications\PoolDigestNotification;
use Modules\Atemschutz\src\Services\ReadinessService;
use Modules\Ausruestung\src\Models\Category;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Ausruestung\src\Notifications\MaintenanceDigestNotification;
use Modules\Ausruestung\src\Services\ReminderService;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeuge\src\Models\Deadline;
use Modules\Fahrzeuge\src\Notifications\DeadlineDigestNotification;
use Modules\Fahrzeuge\src\Services\FleetService;
use Modules\Lehrgaenge\src\Models\Course;
use Modules\Lehrgaenge\src\Models\Record;
use Modules\Lehrgaenge\src\Notifications\RefreshDigestNotification;
use Modules\Lehrgaenge\src\Services\RegistrationService;
use Modules\Maengelmanagement\src\Models\Defect;
use Modules\Maengelmanagement\src\Notifications\DefectDigestNotification;
use Modules\Maengelmanagement\src\Services\DefectService;
use Modules\Unterweisungen\src\Models\Attendance;
use Modules\Unterweisungen\src\Models\Session;
use Modules\Unterweisungen\src\Models\Topic;
use Modules\Unterweisungen\src\Notifications\InstructionDueNotification;
use Modules\Unterweisungen\src\Services\InstructionService;
use Modules\Wartung\src\Models\Task;
use Modules\Wartung\src\Notifications\WartungDigestNotification;
use Modules\Wartung\src\Services\WartungService;

beforeEach(function () {
    config(['locations.enabled' => true]);
    Module::whereIn('key', ['atemschutz', 'fahrzeuge', 'lehrgaenge', 'unterweisungen', 'ausruestung', 'wartung', 'maengelmanagement'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Notification::fake();
    $this->admin = User::factory()->admin()->create();
    $this->north = Location::create(['name' => 'Wache Nord', 'slug' => 'mail-nord']);
    $this->south = Location::create(['name' => 'Wache Süd', 'slug' => 'mail-sued']);
    $this->chiefNorth = User::factory()->create(['name' => 'Wiebke Wehrführung', 'ext_location_id' => $this->north->id]);
    $this->chiefSouth = User::factory()->create(['name' => 'Willi Wehrführung', 'ext_location_id' => $this->south->id]);
    foreach ([[$this->chiefNorth, $this->north], [$this->chiefSouth, $this->south]] as [$chief, $location]) {
        app(StandardRoleAssignments::class)->grant($this->admin, $chief->id, 'wehrfuehrung', $location->id, null, 'Standortleitung');
    }
    $this->northMember = User::factory()->create(['name' => 'Hanna Hydrant', 'ext_location_id' => $this->north->id]);
    $this->southMember = User::factory()->create(['name' => 'Bernd Blaulicht', 'ext_location_id' => $this->south->id]);
    $this->unassigned = User::factory()->create(['name' => 'Otto Ortsbrand']);
});

test('only current active Standort leadership receives local digests', function () {
    $service = app(LocationNotifications::class);
    expect($service->leadership($this->north->id)->modelKeys())->toBe([$this->chiefNorth->id])
        ->and($service->leadership(null))->toBeEmpty();

    foreach ([
        ['granted_at' => now()->subDays(2), 'expires_at' => now()->subMinute()],
        ['granted_at' => now()->subDay(), 'revoked_at' => now(), 'revoked_by' => $this->admin->id, 'revoke_reason' => 'Beendet', 'revoked_source' => 'admin'],
        ['granted_at' => now()->addDay()],
    ] as $dates) {
        $member = User::factory()->create(['ext_location_id' => $this->north->id]);
        StandardRoleAssignment::create([
            'user_id' => $member->id, 'profile' => 'wehrfuehrung', 'location_id' => $this->north->id,
            'granted_by' => $this->admin->id, 'reason' => 'Zeitlich begrenzte Zuständigkeit', ...$dates,
        ]);
    }
    expect($service->leadership($this->north->id)->modelKeys())->toBe([$this->chiefNorth->id]);
    $this->north->update(['is_active' => false]);
    expect($service->leadership($this->north->id))->toBeEmpty();
    $this->north->update(['is_active' => true]);
    $this->chiefNorth->update(['is_active' => false]);
    expect($service->leadership($this->north->id))->toBeEmpty();
    $this->chiefNorth->update(['is_active' => true]);
    config(['locations.enabled' => false]);
    expect($service->leadership($this->north->id))->toBeEmpty();
});

test('a chief of multiple locations gets one digest without unassigned entries', function () {
    $this->chiefNorth->locations()->attach($this->south->id);
    app(StandardRoleAssignments::class)->grant($this->admin, $this->chiefNorth->id, 'wehrfuehrung', $this->south->id, null, 'Vertretung');
    $entries = collect([
        ['location_id' => $this->north->id, 'name' => $this->northMember->name, 'lines' => collect(['Frist Nord'])],
        ['location_id' => $this->south->id, 'name' => $this->southMember->name, 'lines' => collect(['Frist Süd'])],
        ['location_id' => null, 'name' => $this->unassigned->name, 'lines' => collect(['Frist ohne Standort'])],
    ]);
    app(LocationNotifications::class)->digest(
        $entries,
        fn (array $entry) => $entry['location_id'],
        fn (Collection $rows) => new PoolDigestNotification($rows),
        fn (array $entry) => $entry['location_id'] === $this->north->id ? collect([$this->chiefNorth, $this->admin]) : collect([$this->admin]),
    );
    Notification::assertSentToTimes($this->chiefNorth, PoolDigestNotification::class, 1);
    Notification::assertSentTo($this->chiefNorth, PoolDigestNotification::class, function ($notification, $channels, $user) {
        $text = implode(' ', $notification->toMail($user)->introLines);

        return str_contains($text, 'Hanna Hydrant') && str_contains($text, 'Bernd Blaulicht') && ! str_contains($text, 'Otto Ortsbrand');
    });
    Notification::assertSentToTimes($this->admin, PoolDigestNotification::class, 1);
    Notification::assertSentTo($this->admin, PoolDigestNotification::class, fn ($notification, $channels, $user) => str_contains(implode(' ', $notification->toMail($user)->introLines), 'Otto Ortsbrand'));
});

test('deadline overviews reach only the leadership of the affected Standort', function (string $module, string $notificationClass) {
    foreach ([$this->northMember, $this->southMember, $this->unassigned] as $member) {
        switch ($module) {
            case 'atemschutz':
                Wearer::create(['user_id' => $member->id, 'is_agt' => true, 'g263_valid_until' => now()->addDays(10)->toDateString(), 'is_active' => true]);
                break;
            case 'fahrzeuge':
                $vehicle = Vehicle::factory()->create(['name' => $member->name, 'ext_location_id' => $member->ext_location_id]);
                Deadline::create(['vehicle_id' => $vehicle->id, 'title' => 'Prüfung', 'due_on' => now()->subDay()->toDateString(), 'lead_days' => 30, 'is_active' => true]);
                break;
            case 'lehrgaenge':
                $course = Course::firstOrCreate(['name' => 'Auffrischung Muster'], ['repeat_months' => 12]);
                Record::create(['user_id' => $member->id, 'course_id' => $course->id, 'status' => 'completed', 'completed_on' => now()->subYear()->toDateString()]);
                break;
            case 'unterweisungen':
                $topic = Topic::firstOrCreate(['name' => 'Jährliche Unterweisung'], ['interval_months' => 12, 'for_all' => true]);
                $session = Session::create(['topic_id' => $topic->id, 'held_on' => now()->subYear()->toDateString(), 'instructor_name' => 'Ulla Unterweiserin', 'created_by' => $this->admin->id]);
                Attendance::create(['session_id' => $session->id, 'user_id' => $member->id]);
                break;
            case 'ausruestung':
                Equipment::create(['name' => $member->name, 'category_id' => Category::firstOrFail()->id, 'ext_location_id' => $member->ext_location_id, 'next_inspection_at' => now()->subDay()->toDateString()]);
                break;
            case 'wartung':
                $equipment = Equipment::create(['name' => $member->name, 'category_id' => Category::firstOrFail()->id, 'ext_location_id' => $member->ext_location_id]);
                Task::create(['equipment_id' => $equipment->id, 'due_on' => now()->subDay()->toDateString(), 'created_by' => $this->admin->id]);
                break;
            case 'maengelmanagement':
                $defect = Defect::create(['title' => $member->name, 'description' => 'Mangel zur Prüfung', 'reporter_id' => $this->admin->id, 'ext_location_id' => $member->ext_location_id]);
                $defect->forceFill(['created_at' => now()->subDays(3)])->save();
                break;
        }
    }
    $run = fn () => match ($module) {
        'atemschutz' => app(ReadinessService::class)->remind(),
        'fahrzeuge' => app(FleetService::class)->remind(),
        'lehrgaenge' => app(RegistrationService::class)->remindRefresh(),
        'unterweisungen' => app(InstructionService::class)->remind(),
        'ausruestung' => app(ReminderService::class)->sendDigest(),
        'wartung' => app(WartungService::class)->remind(),
        'maengelmanagement' => app(DefectService::class)->remind(),
    };
    $run();
    foreach ([[$this->chiefNorth, 'Hanna Hydrant', 'Bernd Blaulicht'], [$this->chiefSouth, 'Bernd Blaulicht', 'Hanna Hydrant']] as [$chief, $own, $foreign]) {
        Notification::assertSentToTimes($chief, $notificationClass, 1);
        Notification::assertSentTo($chief, $notificationClass, function ($notification, $channels, $user) use ($own, $foreign) {
            $mail = $notification->toMail($user);
            $text = implode(' ', $mail->introLines);

            return str_contains($text, $own) && ! str_contains($text, $foreign) && ! str_contains($text, 'Otto Ortsbrand');
        });
    }
    Notification::assertSentTo($this->admin, $notificationClass, fn ($notification, $channels, $user) => str_contains(implode(' ', $notification->toMail($user)->introLines), 'Otto Ortsbrand'));
    if (in_array($module, ['lehrgaenge', 'unterweisungen'], true)) {
        Notification::assertSentTo($this->chiefNorth, $notificationClass, fn ($notification, $channels, $user) => $notification->toMail($user)->actionUrl === null && $notification->toDatabase($user)['url'] === null);
    }
    Notification::fake();
    $run();
    Notification::assertNothingSent();
})->with([
    ['atemschutz', PoolDigestNotification::class],
    ['fahrzeuge', DeadlineDigestNotification::class],
    ['lehrgaenge', RefreshDigestNotification::class],
    ['unterweisungen', InstructionDueNotification::class],
    ['ausruestung', MaintenanceDigestNotification::class],
    ['wartung', WartungDigestNotification::class],
    ['maengelmanagement', DefectDigestNotification::class],
]);

<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\User;
use Extensions\Anwesenheit\Models\AttendanceList;
use Extensions\Hydrantenkontrolle\Models\Hydrant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Kleiderkammer\database\seeders\KleiderkammerDemoDataSeeder;
use RuntimeException;

/**
 * Fork: example installation for the E2E screenshots (tests/e2e, docs-extended/SCREENSHOTS.md).
 * Runs the demo seeders, switches on the modules that are photographed and adds a little
 * content, so the pages are not empty. Invented people only; never run on a real database.
 *
 *   php artisan migrate:fresh --seed --seeder=Database\\Seeders\\ScreenshotSeeder
 */
class ScreenshotSeeder extends Seeder
{
    /** Modules switched on for the screenshots (admins use every active module; new Kameraden get access) */
    public const MODULES = ['fahrzeugbuchung', 'kleiderkammer', 'kalender', 'anwesenheit', 'hydrantenkontrolle'];

    public function run(): void
    {
        // Not DatabaseSeeder: DemoDataSeeder upserts on vehicles.name/groups.name, which have no unique
        // index on a fresh database; these seeders create the same rows with firstOrCreate
        $this->call([VehicleSeeder::class, GroupSeeder::class, UserSeeder::class, KleiderkammerDemoDataSeeder::class]);

        foreach (self::MODULES as $key) {
            Module::updateOrCreate(['key' => $key], ['is_active' => true, 'auto_assign' => true]);
        }

        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $hanna = User::where('email', 'hanna.hydrant@example.com')->firstOrFail();
        $bernd = User::where('email', 'bernd.blaulicht@example.com')->firstOrFail();

        // Fixed weekdays of the current week (Berlin), so the calendar's week view always shows them,
        // plus one booking next week that stays under "Meine Buchungen → Kommend"
        $week = Carbon::now('Europe/Berlin')->startOfWeek();
        $at = fn (int $days, string $time) => $week->copy()->addDays($days)->setTimeFromTimeString($time)->utc();

        $vehicles = Vehicle::orderBy('sort_order')->get()->keyBy('short_name');
        $bookings = [
            ['MTW-A', $admin, 0, '09:00', '12:00', 'Einkauf Übungsmaterial'],
            ['MTW-N', $hanna, 2, '17:00', '21:00', 'Jugendfeuerwehr-Ausflug'],
            ['JF-Anh', $bernd, 4, '08:00', '16:00', 'Zeltlager Aufbau'],
            ['MTW-N', $admin, 8, '10:00', '13:00', 'Fahrzeugpflege'],
        ];
        foreach ($bookings as [$vehicle, $user, $day, $from, $to, $purpose]) {
            // updateOrCreate: a re-run in a later week moves the bookings into that week
            Booking::updateOrCreate(['vehicle_id' => ($vehicles->get($vehicle) ?? throw new RuntimeException("Vehicle {$vehicle} is missing"))->id, 'purpose' => $purpose], [
                'user_id' => $user->id, 'starts_at' => $at($day, $from), 'ends_at' => $at($day, $to), 'status' => 'confirmed', 'cancelled_at' => null,
            ]);
        }

        $hydrants = [
            ['number' => 'H-001', 'street' => 'Hauptstraße', 'house_number' => '1', 'location_description' => 'Vor dem Gerätehaus'],
            ['number' => 'H-002', 'street' => 'Schulweg', 'house_number' => '12', 'location_description' => 'Gegenüber der Grundschule'],
            ['number' => 'H-003', 'street' => 'Am Sportplatz', 'house_number' => null, 'location_description' => 'Parkplatz Einfahrt'],
        ];
        foreach ($hydrants as $hydrant) {
            Hydrant::firstOrCreate(['number' => $hydrant['number']], $hydrant);
        }

        $list = AttendanceList::updateOrCreate(['title' => 'Übungsdienst Technische Hilfe'], [
            'kind' => 'uebung',
            'status' => AttendanceList::SUBMITTED,
            'starts_at' => $at(-5, '19:00'),
            'ends_at' => $at(-5, '21:30'),
            'duration_minutes' => 150,
            'leader_id' => $hanna->id,
            'participant_count' => 2,
            'submitted_at' => $at(-5, '21:45'),
            'created_by' => $hanna->id,
        ]);
        $list->participants()->syncWithoutDetaching([$hanna->id, $bernd->id]);
    }
}

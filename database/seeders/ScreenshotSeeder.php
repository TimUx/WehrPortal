<?php

namespace Database\Seeders;

use App\Models\MemberFunction;
use App\Models\Module;
use App\Models\User;
use App\Services\ModuleRegistry;
use Extensions\Anwesenheit\Models\AttendanceList;
use Extensions\Dateien\Models\Space;
use Extensions\Dateien\Services\FileService;
use Extensions\Hydrantenkontrolle\Models\Hydrant;
use Extensions\Hydrantenkontrolle\Models\Inspection;
use Extensions\Hydrantenkontrolle\Services\InspectionService;
use Extensions\Locations\Models\Location;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Atemschutz\src\Models\Training;
use Modules\Atemschutz\src\Models\Wearer;
use Modules\Ausruestung\src\Models\Category;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Einsatzbericht\src\Models\CrewMember;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Einsatzbericht\src\Models\ReportVehicle;
use Modules\Expositionen\src\Models\Exposure;
use Modules\Fahrtenbuch\src\Models\Trip;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeuge\src\Models\Deadline;
use Modules\Fahrzeuge\src\Models\Profile;
use Modules\Finanzen\src\Models\Budget;
use Modules\Finanzen\src\Models\CostCenter;
use Modules\Finanzen\src\Models\Entry as FinanceEntry;
use Modules\Fuehrerscheine\src\Models\License;
use Modules\Gefahrstoffe\src\Models\Material;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Models\Response;
use Modules\Kleiderkammer\database\seeders\KleiderkammerDemoDataSeeder;
use Modules\Lehrgaenge\src\Models\Course;
use Modules\Lehrgaenge\src\Models\Record;
use Modules\Maengelmanagement\src\Models\Defect;
use Modules\Maengelmanagement\src\Models\Device;
use Modules\Mitteilungen\src\Models\Announcement;
use Modules\Objektplaene\src\Models\PlanObject;
use Modules\Personalverwaltung\src\Models\Honor;
use Modules\Personalverwaltung\src\Models\HonorType;
use Modules\Schichtplan\src\Models\Plan;
use Modules\Schichtplan\src\Models\Slot;
use Modules\Schichtplan\src\Models\Station;
use Modules\Schluessel\src\Models\Key;
use Modules\Uebungsdienste\src\Models\Service;
use Modules\Unterweisungen\src\Models\Attendance;
use Modules\Unterweisungen\src\Models\Session as InstructionSession;
use Modules\Unterweisungen\src\Models\Topic;
use Modules\Verbandbuch\src\Models\Entry as FirstAidEntry;
use Modules\Verbandbuch\src\Services\VerbandbuchService;
use Modules\Wartung\src\Models\Task;
use Modules\Wartung\src\Models\Template;
use Modules\Wasserkarte\src\Models\Source;
use RuntimeException;

/**
 * Fork: example installation for the E2E screenshots (tests/e2e, docs-extended/SCREENSHOTS.md).
 * Runs the demo seeders, switches on every module and fills each with a little content, so every
 * page that is photographed shows something. All dates are relative to now(); the screenshot run
 * freezes the clock (E2E_FROZEN_NOW, extensions/E2e), so the data is the same in every run.
 * Invented people only; never run on a real database.
 *
 *   php artisan migrate:fresh --seed --seeder=Database\\Seeders\\ScreenshotSeeder
 */
class ScreenshotSeeder extends Seeder
{
    private User $admin;

    private User $hanna;

    private User $bernd;

    /** @var Collection<string, Vehicle> by short name */
    private $vehicles;

    public function run(): void
    {
        // Not DatabaseSeeder: DemoDataSeeder upserts on vehicles.name/groups.name, which have no unique
        // index on a fresh database; these seeders create the same rows with firstOrCreate
        $this->call([VehicleSeeder::class, GroupSeeder::class, UserSeeder::class, KleiderkammerDemoDataSeeder::class]);

        foreach (self::modules() as $key) {
            Module::updateOrCreate(['key' => $key], ['is_active' => true, 'auto_assign' => true]);
        }

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->hanna = User::where('email', 'hanna.hydrant@example.com')->firstOrFail();
        $this->bernd = User::where('email', 'bernd.blaulicht@example.com')->firstOrFail();
        $this->vehicles = Vehicle::orderBy('sort_order')->get()->keyBy('short_name');

        // The Übungsdienstplaner is only meant for the Vorstand
        $vorstand = MemberFunction::firstOrCreate(['name' => MemberFunction::VORSTAND, 'parent_id' => null], ['sort_order' => 1]);
        $this->admin->functions()->syncWithoutDetaching([$vorstand->id]);

        $this->fahrzeugbuchung();
        $this->hydrantenkontrolle();
        $this->anwesenheit();
        $this->mitteilungen();
        $this->kalender();
        $this->maengelmanagement();
        $this->ausruestung();
        $this->wartung();
        $this->schichtplan();
        $this->lehrgaenge();
        $this->uebungsdienste();
        $this->einsatzbericht();
        $this->finanzen();
        $this->verbandbuch();
        $this->expositionen();
        $this->fuehrerscheine();
        $this->unterweisungen();
        $this->atemschutz();
        $this->fahrzeuge();
        $this->fahrtenbuch();
        $this->wasserkarte();
        $this->objektplaene();
        $this->personalverwaltung();
        $this->schluessel();
        $this->gefahrstoffe();
        $this->dateien();
        $this->locations();
    }

    /** Every registered module (upstream modules and fork extensions) is switched on for the screenshots */
    public static function modules(): array
    {
        return array_keys(app(ModuleRegistry::class)->all());
    }

    /** A time on a day relative to the start of the current week (Berlin), stored as UTC */
    private function at(int $days, string $time): Carbon
    {
        return Carbon::now('Europe/Berlin')->startOfWeek()->addDays($days)->setTimeFromTimeString($time)->utc();
    }

    private function vehicle(string $short): Vehicle
    {
        return $this->vehicles->get($short) ?? throw new RuntimeException("Vehicle {$short} is missing");
    }

    private function fahrzeugbuchung(): void
    {
        // Fixed weekdays of the current week, so the calendar's week view always shows them, plus one
        // booking next week that stays under "Meine Buchungen → Kommend"
        $bookings = [
            ['MTW-A', $this->admin, 0, '09:00', '12:00', 'Einkauf Übungsmaterial'],
            ['MTW-N', $this->hanna, 2, '17:00', '21:00', 'Jugendfeuerwehr-Ausflug'],
            ['JF-Anh', $this->bernd, 4, '08:00', '16:00', 'Zeltlager Aufbau'],
            ['MTW-N', $this->admin, 8, '10:00', '13:00', 'Fahrzeugpflege'],
        ];
        foreach ($bookings as [$vehicle, $user, $day, $from, $to, $purpose]) {
            // updateOrCreate: a re-run in a later week moves the bookings into that week
            Booking::updateOrCreate(['vehicle_id' => $this->vehicle($vehicle)->id, 'purpose' => $purpose], [
                'user_id' => $user->id, 'starts_at' => $this->at($day, $from), 'ends_at' => $this->at($day, $to), 'status' => 'confirmed', 'cancelled_at' => null,
            ]);
        }
    }

    private function hydrantenkontrolle(): void
    {
        $hydrants = [
            ['number' => 'H-001', 'street' => 'Hauptstraße', 'house_number' => '1', 'location_description' => 'Vor dem Gerätehaus'],
            ['number' => 'H-002', 'street' => 'Schulweg', 'house_number' => '12', 'location_description' => 'Gegenüber der Grundschule'],
            ['number' => 'H-003', 'street' => 'Am Sportplatz', 'house_number' => null, 'location_description' => 'Parkplatz Einfahrt'],
        ];
        foreach ($hydrants as $hydrant) {
            Hydrant::firstOrCreate(['number' => $hydrant['number']], $hydrant);
        }

        // A running Jahreskontrolle: one hydrant in order, one with a defect, one still open
        $service = app(InspectionService::class);
        $year = $service->openYear($this->admin, now('Europe/Berlin')->year);
        $results = ['H-001' => ['is_ok' => true, 'defects' => []], 'H-002' => ['is_ok' => false, 'defects' => ['cover_stuck']]];
        /** @var Inspection $inspection */
        foreach ($year->inspections()->get() as $inspection) {
            $result = $results[$inspection->snapshot['number'] ?? ''] ?? null;
            if ($result && $inspection->checked_at === null) {
                $service->saveInspection($this->admin, $inspection, [...$result, 'checked_at' => now()->subDays(2)->toDateString()], $inspection->version);
            }
        }
    }

    private function anwesenheit(): void
    {
        $list = AttendanceList::updateOrCreate(['title' => 'Übungsdienst Technische Hilfe'], [
            'kind' => 'uebung',
            'status' => AttendanceList::SUBMITTED,
            'starts_at' => $this->at(-5, '19:00'),
            'ends_at' => $this->at(-5, '21:30'),
            'duration_minutes' => 150,
            'leader_id' => $this->hanna->id,
            'participant_count' => 2,
            'submitted_at' => $this->at(-5, '21:45'),
            'created_by' => $this->hanna->id,
        ]);
        $list->participants()->syncWithoutDetaching([$this->hanna->id, $this->bernd->id]);
    }

    private function mitteilungen(): void
    {
        Announcement::updateOrCreate(['title' => 'Neue Einsatzjacken eingetroffen'], [
            'body' => 'Die neuen Einsatzjacken sind da. Bitte bis Monatsende in der Kleiderkammer abholen und die alte Jacke mitbringen.',
            'important' => true, 'needs_ack' => true, 'visible_from' => now()->subDays(2), 'created_by' => $this->admin->id,
        ]);
        Announcement::updateOrCreate(['title' => 'Grillfest der Jugendfeuerwehr'], [
            'body' => 'Am Samstag grillt die Jugendfeuerwehr am Gerätehaus. Familien sind herzlich eingeladen.',
            'important' => false, 'needs_ack' => false, 'visible_from' => now()->subDays(5), 'created_by' => $this->hanna->id,
        ]);
    }

    private function kalender(): void
    {
        $events = [
            ['Übungsdienst: Technische Hilfe', 'uebung', 1, '19:00', '21:30', 'Gerätehaus'],
            ['Dienstbesprechung Führungskräfte', 'dienst', 3, '19:30', '21:00', 'Schulungsraum'],
            ['Tag der offenen Tür', 'veranstaltung', 5, '10:00', '17:00', 'Gerätehaus'],
        ];
        foreach ($events as [$title, $type, $day, $from, $to, $location]) {
            $event = Event::updateOrCreate(['title' => $title], [
                'type' => $type, 'starts_at' => $this->at($day, $from), 'ends_at' => $this->at($day, $to), 'location' => $location,
                'rsvp' => true, 'created_by' => $this->admin->id,
            ]);
            Response::updateOrCreate(['event_id' => $event->id, 'user_id' => $this->hanna->id], ['status' => 'yes']);
            Response::updateOrCreate(['event_id' => $event->id, 'user_id' => $this->bernd->id], ['status' => 'no']);
        }
    }

    private function maengelmanagement(): void
    {
        $device = Device::firstOrCreate(['code' => 'GH-TOR-1'], ['name' => 'Rolltor Halle 1', 'location' => 'Fahrzeughalle', 'is_active' => true, 'responsible_id' => $this->bernd->id]);
        Defect::firstOrCreate(['title' => 'Rolltor schließt nicht vollständig'], [
            'device_id' => $device->id, 'description' => 'Das Tor bleibt etwa 20 cm über dem Boden stehen.', 'urgency' => 'high', 'status' => 'open',
            'reporter_id' => $this->hanna->id, 'assigned_to' => $this->bernd->id,
        ]);
        Defect::firstOrCreate(['title' => 'Blaulicht vorne links defekt'], [
            'vehicle_id' => $this->vehicle('MTW-N')->id, 'description' => 'Rundumkennleuchte flackert nur noch.', 'urgency' => 'medium', 'status' => 'open',
            'reporter_id' => $this->admin->id,
        ]);
    }

    private function ausruestung(): void
    {
        $category = Category::firstOrCreate(['name' => 'Atemschutzgeräte'], ['inspection_interval_months' => 12, 'sort_order' => 1]);
        $hoses = Category::firstOrCreate(['name' => 'Schläuche'], ['inspection_interval_months' => 12, 'sort_order' => 2]);
        $items = [
            ['PA-001', 'Pressluftatmer PA 1', $category, 'Dräger', 'PSS 5000', 20],
            ['PA-002', 'Pressluftatmer PA 2', $category, 'Dräger', 'PSS 5000', 75],
            ['B-001', 'B-Druckschlauch 20 m', $hoses, 'Vetter', 'B 75', 200],
        ];
        foreach ($items as [$code, $name, $cat, $manufacturer, $model, $days]) {
            Equipment::firstOrCreate(['code' => $code], [
                'name' => $name, 'category_id' => $cat->id, 'manufacturer' => $manufacturer, 'model' => $model, 'status' => 'available',
                'inspection_interval_months' => 12, 'next_inspection_at' => now()->addDays($days)->toDateString(),
            ]);
        }
    }

    private function wartung(): void
    {
        $category = Category::where('name', 'Atemschutzgeräte')->firstOrFail();
        $template = Template::firstOrCreate(['name' => 'Jahresprüfung Pressluftatmer'], [
            'description' => 'Sicht-, Funktions- und Dichtprüfung nach Herstellervorgabe', 'category_id' => $category->id, 'interval_months' => 12,
            'lead_days' => 30, 'checklist' => ['Sichtprüfung', 'Dichtprüfung', 'Funktionsprüfung'], 'counts_as_inspection' => true,
            'performer' => 'internal', 'is_active' => true,
        ]);
        foreach (Equipment::where('category_id', $category->id)->get() as $equipment) {
            Task::firstOrCreate(['template_id' => $template->id, 'equipment_id' => $equipment->id], [
                'due_on' => $equipment->next_inspection_at, 'status' => 'open', 'created_by' => $this->admin->id,
            ]);
        }
    }

    private function schichtplan(): void
    {
        $plan = Plan::updateOrCreate(['title' => 'Tag der offenen Tür'], [
            'location' => 'Gerätehaus', 'starts_at' => $this->at(5, '10:00'), 'ends_at' => $this->at(5, '16:00'), 'shift_minutes' => 120,
            'status' => Plan::PUBLISHED, 'published_at' => now()->subDay(), 'created_by' => $this->admin->id, 'signup' => true,
        ]);
        foreach (['Grillstand' => 2, 'Kuchenbuffet' => 2, 'Fahrzeugschau' => 1] as $i => $capacity) {
            Station::firstOrCreate(['plan_id' => $plan->id, 'name' => $i], ['capacity' => $capacity, 'sort_order' => $capacity]);
        }
        foreach (['10:00', '12:00', '14:00'] as $from) {
            $start = $this->at(5, $from);
            Slot::firstOrCreate(['plan_id' => $plan->id, 'starts_at' => $start], ['ends_at' => $start->copy()->addHours(2)]);
        }
    }

    private function lehrgaenge(): void
    {
        $courses = [];
        foreach (['Truppmann Teil 1', 'Sprechfunker', 'Atemschutzgeräteträger', 'Maschinist'] as $name) {
            $courses[$name] = Course::firstOrCreate(['name' => $name], ['listed' => true, 'is_standard' => true]);
        }
        Record::firstOrCreate(['user_id' => $this->admin->id, 'course_id' => $courses['Truppmann Teil 1']->id], ['status' => 'completed', 'completed_on' => now()->subYears(3)->toDateString(), 'entered_manually' => true]);
        Record::firstOrCreate(['user_id' => $this->admin->id, 'course_id' => $courses['Sprechfunker']->id], ['status' => 'completed', 'completed_on' => now()->subYears(2)->toDateString(), 'entered_manually' => true]);
        Record::firstOrCreate(['user_id' => $this->admin->id, 'course_id' => $courses['Maschinist']->id], ['status' => 'requested']);
        Record::firstOrCreate(['user_id' => $this->hanna->id, 'course_id' => $courses['Atemschutzgeräteträger']->id], ['status' => 'completed', 'completed_on' => now()->subYear()->toDateString(), 'entered_manually' => true]);
    }

    private function uebungsdienste(): void
    {
        $services = [
            [1, 'Technische Hilfe: Rettungssatz und Stabilisierung', [$this->hanna->id]],
            [8, 'Wasserförderung über lange Wegstrecken', [$this->bernd->id]],
            [15, null, []],
        ];
        foreach ($services as [$day, $topic, $users]) {
            $service = Service::updateOrCreate(['date' => $this->at($day, '00:00')->setTimezone('Europe/Berlin')->toDateString()], [
                'starts_at' => '19:00', 'ends_at' => '21:00', 'location' => 'Gerätehaus', 'topic' => $topic,
            ]);
            $service->responsible()->sync($users);
        }
    }

    private function einsatzbericht(): void
    {
        $report = Report::updateOrCreate(['number' => now('Europe/Berlin')->year.'-001'], [
            'keyword' => 'TH 1 – Ölspur', 'status' => 'released', 'category' => 'th_klein',
            'alarmed_at' => $this->at(-3, '14:12'), 'arrived_at' => $this->at(-3, '14:20'), 'ended_at' => $this->at(-3, '15:05'),
            'address' => 'Hauptstraße 40, Musterstadt', 'situation' => 'Ölspur über ca. 200 m nach Unfall.', 'measures' => 'Bindemittel aufgebracht, Straße gereinigt.',
            'commander_id' => $this->hanna->id, 'created_by' => $this->hanna->id, 'closed_at' => $this->at(-3, '17:00'), 'closed_by' => $this->hanna->id,
            'released_at' => $this->at(-2, '09:00'), 'released_by' => $this->admin->id,
        ]);
        $vehicle = ReportVehicle::firstOrCreate(['report_id' => $report->id, 'vehicle_id' => $this->vehicle('MTW-N')->id], ['name' => 'MTW neu', 'sort_order' => 1]);
        foreach ([[$this->hanna, 'Einheitsführerin'], [$this->bernd, 'Maschinist']] as [$user, $function]) {
            CrewMember::firstOrCreate(['report_id' => $report->id, 'user_id' => $user->id], ['report_vehicle_id' => $vehicle->id, 'function' => $function]);
        }
    }

    private function finanzen(): void
    {
        $year = now('Europe/Berlin')->year;
        $centers = [];
        foreach ([['Einsatzabteilung', 'EA', 5000_00], ['Jugendfeuerwehr', 'JF', 1500_00], ['Kameradschaftskasse', 'KK', 2000_00]] as $i => [$name, $code, $amount]) {
            $centers[$code] = CostCenter::firstOrCreate(['name' => $name], ['code' => $code, 'is_active' => true, 'sort_order' => $i]);
            Budget::firstOrCreate(['cost_center_id' => $centers[$code]->id, 'year' => $year], ['amount_cents' => $amount]);
        }
        /** @var list<array{int, string, string, string, string, ?string, int}> $entries */
        $entries = [
            [1, 'expense', 'paid', 'EA', 'Bindemittel 10 Sack', 'Feuerwehrbedarf Muster', 189_90],
            [2, 'expense', 'submitted', 'JF', 'Zeltlager Verpflegung', 'Großmarkt Musterstadt', 342_50],
            [3, 'income', 'approved', 'KK', 'Spende Tag der offenen Tür', null, 450_00],
        ];
        foreach ($entries as [$number, $kind, $status, $code, $title, $vendor, $cents]) {
            FinanceEntry::firstOrCreate(['year' => $year, 'number' => $number], [
                'kind' => $kind, 'status' => $status, 'cost_center_id' => $centers[$code]->id, 'title' => $title, 'vendor' => $vendor,
                'amount_cents' => $cents, 'booked_on' => now()->subDays(10 - $number)->toDateString(), 'submitted_by' => $this->hanna->id,
                'decided_by' => $status === 'submitted' ? null : $this->admin->id, 'decided_at' => $status === 'submitted' ? null : now()->subDays(5),
            ]);
        }
    }

    private function verbandbuch(): void
    {
        if (FirstAidEntry::exists()) {
            return;
        }
        app(VerbandbuchService::class)->create($this->admin, [
            'kind' => 'injury', 'occasion' => 'uebung', 'occurred_at' => $this->at(-5, '20:10')->toIso8601String(), 'location' => 'Gerätehaus, Übungshof',
            'injured_user_id' => $this->bernd->id, 'description' => 'Beim Aufrollen eines Schlauchs an der Kupplung geschnitten.',
            'injury' => 'Schnittwunde am Zeigefinger rechts', 'first_aid_measures' => 'Wunde gereinigt, Pflaster', 'first_aider_user_id' => $this->hanna->id,
        ], self::signature());
    }

    private function expositionen(): void
    {
        Exposure::firstOrCreate(['user_id' => $this->bernd->id, 'occurred_on' => $this->at(-3, '12:00')->toDateString()], [
            'person_name' => $this->bernd->name, 'occasion' => 'einsatz', 'activity' => ['nachloeschen'], 'substances' => ['Brandrauch / Brandgase (PAK, Ruß)'],
            'duration_minutes' => 45, 'routes' => ['inhalativ'], 'protection' => ['schutzkleidung', 'filter'], 'status' => 'recorded',
            'recorded_by' => $this->admin->id, 'retain_until' => now()->addYears(Exposure::RETAIN_YEARS)->toDateString(),
        ]);
    }

    private function fuehrerscheine(): void
    {
        foreach ([[$this->hanna, ['B', 'BE', 'C1', 'FW75']], [$this->bernd, ['B', 'C', 'CE']], [$this->admin, ['B', 'FW475']]] as [$user, $classes]) {
            // C classes run out after five years (License::LIMITED); the others are unlimited
            $classes = array_map(fn (string $class) => ['class' => $class, 'valid_until' => in_array($class, License::LIMITED, true) ? now()->addYears(3)->toDateString() : null], $classes);
            License::updateOrCreate(['user_id' => $user->id], [
                'classes' => $classes, 'status' => 'validated', 'submitted_at' => now()->subMonths(2), 'validated_at' => now()->subMonths(2),
                'validated_by' => $this->admin->id, 'next_check_on' => now()->addMonths(4)->toDateString(),
                'document_valid_until' => now()->addYears(5)->toDateString(),
            ]);
        }
    }

    private function unterweisungen(): void
    {
        $topic = Topic::firstOrCreate(['name' => 'Jährliche Sicherheitsunterweisung'], [
            'short' => 'SU', 'kind' => 'unterweisung', 'interval_months' => 12, 'for_all' => true, 'is_active' => true,
            'description' => 'Unfallverhütung im Feuerwehrdienst (DGUV Vorschrift 49)',
        ]);
        Topic::firstOrCreate(['name' => 'Einweisung MTW neu'], [
            'short' => 'MTW', 'kind' => 'einweisung', 'interval_months' => 24, 'vehicle_id' => $this->vehicle('MTW-N')->id, 'for_all' => true, 'is_active' => true,
        ]);
        $session = InstructionSession::firstOrCreate(['topic_id' => $topic->id, 'held_on' => now()->subMonths(3)->toDateString()], [
            'instructor_name' => $this->hanna->name, 'instructor_id' => $this->hanna->id, 'created_by' => $this->admin->id,
        ]);
        foreach ([$this->admin, $this->bernd] as $user) {
            Attendance::firstOrCreate(['session_id' => $session->id, 'user_id' => $user->id], ['signed_at' => now()->subMonths(3)]);
        }
    }

    private function atemschutz(): void
    {
        foreach ([[$this->hanna, 10], [$this->bernd, 3]] as [$user, $months]) {
            Wearer::firstOrCreate(['user_id' => $user->id], [
                'is_agt' => true, 'agt_qualified_on' => now()->subYears(4)->toDateString(), 'g263_examined_on' => now()->subMonths(36 - $months)->toDateString(),
                'g263_valid_until' => now()->addMonths($months)->toDateString(), 'is_active' => true,
            ]);
            Training::firstOrCreate(['user_id' => $user->id, 'kind' => 'belastungsuebung'], ['done_on' => now()->subMonths(4)->toDateString(), 'created_by' => $this->admin->id]);
            Training::firstOrCreate(['user_id' => $user->id, 'kind' => 'unterweisung'], ['done_on' => now()->subMonths(6)->toDateString(), 'created_by' => $this->admin->id]);
        }
    }

    private function fahrzeuge(): void
    {
        $profiles = [
            'MTW-A' => ['call_sign' => 'Florian Musterstadt 19/1', 'license_plate' => 'MU-FW 191', 'category' => 'transport', 'year_built' => 2008, 'crew' => '1/8', 'license_class' => 'B', 'mileage_km' => 184300, 'status' => 'limited', 'status_note' => 'Blaulicht vorne links defekt'],
            'MTW-N' => ['call_sign' => 'Florian Musterstadt 19/2', 'license_plate' => 'MU-FW 192', 'category' => 'transport', 'year_built' => 2023, 'crew' => '1/8', 'license_class' => 'B', 'mileage_km' => 21450, 'status' => 'ready'],
            'JF-Anh' => ['license_plate' => 'MU-FW 193', 'category' => 'anhaenger', 'year_built' => 2015, 'license_class' => 'BE', 'status' => 'ready'],
        ];
        foreach ($profiles as $short => $data) {
            Profile::updateOrCreate(['vehicle_id' => $this->vehicle($short)->id], $data);
        }
        Deadline::firstOrCreate(['vehicle_id' => $this->vehicle('MTW-A')->id, 'title' => 'Hauptuntersuchung (HU)'], [
            'interval_months' => 12, 'due_on' => now()->addDays(21)->toDateString(), 'lead_days' => 30, 'is_active' => true,
        ]);
        Deadline::firstOrCreate(['vehicle_id' => $this->vehicle('MTW-N')->id, 'title' => 'Hauptuntersuchung (HU)'], [
            'interval_months' => 24, 'due_on' => now()->addMonths(14)->toDateString(), 'lead_days' => 30, 'is_active' => true,
        ]);
    }

    private function fahrtenbuch(): void
    {
        $trips = [
            ['MTW-N', $this->hanna, -3, '14:05', '15:20', 21380, 21412, 'einsatz', 'Hauptstraße 40'],
            ['MTW-A', $this->admin, 0, '09:05', '11:50', 184250, 184300, 'dienstfahrt', 'Feuerwehrbedarf Muster'],
            ['MTW-N', $this->bernd, -6, '18:00', '22:10', 21300, 21380, 'jugend', 'Kreiszeltlager'],
        ];
        foreach ($trips as [$short, $driver, $day, $from, $to, $startKm, $endKm, $purpose, $destination]) {
            Trip::firstOrCreate(['vehicle_id' => $this->vehicle($short)->id, 'start_km' => $startKm], [
                'driver_id' => $driver->id, 'started_at' => $this->at($day, $from), 'ended_at' => $this->at($day, $to), 'end_km' => $endKm,
                'origin' => 'Gerätehaus', 'destination' => $destination, 'purpose' => $purpose, 'created_by' => $driver->id,
            ]);
        }
    }

    private function wasserkarte(): void
    {
        $sources = [
            ['WK-1', 'unterflur', 50.1105, 8.6820, 'Hauptstraße 1', 'available', 1600],
            ['WK-2', 'ueberflur', 50.1121, 8.6851, 'Schulweg 12', 'available', 1200],
            ['WK-3', 'teich', 50.1089, 8.6795, 'Am Sportplatz', 'defective', null],
        ];
        foreach ($sources as [$number, $kind, $lat, $lng, $location, $status, $flow]) {
            Source::firstOrCreate(['number' => $number], [
                'kind' => $kind, 'latitude' => $lat, 'longitude' => $lng, 'location' => $location, 'status' => $status, 'flow_lpm' => $flow,
                'is_active' => true, 'capacity_m3' => $kind === 'teich' ? 300 : null, 'status_note' => $status === 'defective' ? 'Zufahrt zugewachsen' : null,
            ]);
        }
    }

    private function objektplaene(): void
    {
        PlanObject::firstOrCreate(['name' => 'Grundschule Musterstadt'], [
            'object_type' => 'schule', 'hazard_class' => 'erhoeht', 'address' => 'Schulweg 10, Musterstadt', 'latitude' => 50.1118, 'longitude' => 8.6845,
            'has_bma' => true, 'has_fsd' => true, 'access' => 'Feuerwehrzufahrt über den Schulhof', 'review_interval_months' => 24,
            'last_reviewed_on' => now()->subYear()->toDateString(), 'next_review_on' => now()->addYear()->toDateString(), 'is_active' => true,
        ]);
        PlanObject::firstOrCreate(['name' => 'Autohaus Muster GmbH'], [
            'object_type' => 'industrie', 'hazard_class' => 'hoch', 'address' => 'Industriestraße 5, Musterstadt', 'latitude' => 50.1067, 'longitude' => 8.6902,
            'has_bma' => true, 'has_hazardous_materials' => true, 'review_interval_months' => 12,
            'last_reviewed_on' => now()->subMonths(13)->toDateString(), 'next_review_on' => now()->subMonth()->toDateString(), 'is_active' => true,
        ]);
    }

    private function personalverwaltung(): void
    {
        $types = [];
        foreach ([['Ehrennadel 10 Jahre', 10], ['Ehrenzeichen Silber 25 Jahre', 25]] as $i => [$name, $years]) {
            $types[$years] = HonorType::firstOrCreate(['name' => $name], ['after_years' => $years, 'sort_order' => $i]);
        }
        Honor::firstOrCreate(['user_id' => $this->hanna->id, 'honor_type_id' => $types[10]->id], [
            'title' => $types[10]->name, 'awarded_on' => now()->subYears(2)->toDateString(), 'awarded_by' => 'Bürgermeister', 'created_by' => $this->admin->id,
        ]);
    }

    private function schluessel(): void
    {
        Key::firstOrCreate(['code' => 'S-01'], ['name' => 'Gerätehaus Haupteingang', 'kind' => 'schluessel', 'locking' => 'Generalschließanlage', 'storage' => 'Schlüsselkasten Büro', 'status' => 'available']);
        Key::firstOrCreate(['code' => 'T-01'], ['name' => 'Transponder Fahrzeughalle', 'kind' => 'transponder', 'storage' => 'Schlüsselkasten Büro', 'status' => 'available']);
        Key::firstOrCreate(['code' => 'S-02'], ['name' => 'Objektschlüssel Grundschule', 'kind' => 'schluessel', 'storage' => 'Schlüsseldepot FSD', 'status' => 'available']);
    }

    private function gefahrstoffe(): void
    {
        $materials = [
            ['1202', 'Dieselkraftstoff', '3', 'III', '30'],
            ['1203', 'Benzin', '3', 'II', '33'],
            ['1005', 'Ammoniak, wasserfrei', '2', null, '268'],
            ['1017', 'Chlor', '2', null, '265'],
        ];
        foreach ($materials as [$un, $name, $class, $group, $hazard]) {
            Material::firstOrCreate(['un_number' => $un], [
                'name' => $name, 'adr_class' => $class, 'packing_group' => $group, 'hazard_number' => $hazard,
                'source_name' => 'Beispieldaten openwehr', 'imported_by' => $this->admin->id, 'imported_at' => now(),
            ]);
        }
    }

    private function dateien(): void
    {
        if (Space::where('name', 'Wehrführung')->exists()) {
            return;
        }
        $files = app(FileService::class);
        $space = $files->createSpace($this->admin, ['name' => 'Wehrführung']);
        foreach (['Dienstpläne', 'Protokolle', 'Vorlagen'] as $folder) {
            $files->createFolder($this->admin, $space, null, $folder);
        }
    }

    private function locations(): void
    {
        if (! config('locations.enabled')) {
            return;
        }
        // The migration creates the default Standort; it becomes Musterstadt-Mitte, plus a second one
        Location::default()->update(['name' => 'Musterstadt-Mitte', 'short_name' => 'Mitte']);
        Location::firstOrCreate(['slug' => 'musterstadt-nord'], ['name' => 'Musterstadt-Nord', 'short_name' => 'Nord', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
    }

    /** A signature as the signature pad sends it (PNG data URL) */
    private static function signature(): string
    {
        $image = imagecreatetruecolor(600, 160);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagesetthickness($image, 3);
        $ink = imagecolorallocate($image, 17, 24, 39);
        imageline($image, 40, 120, 300, 40, $ink);
        imageline($image, 300, 40, 560, 110, $ink);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($png);
    }
}

<?php

use App\Models\Group;
use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Modules\Einsatzbericht\src\Services\ReportService;
use Modules\Fahrtenbuch\src\Models\Trip;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Kalender\src\Services\EventService;
use Modules\Lehrgaenge\src\Models\Course;
use Modules\Lehrgaenge\src\Models\Record;
use Modules\Personalverwaltung\src\Services\PersonnelService;

beforeEach(function () {
    Module::whereIn('key', ['einsatzbericht', 'kalender', 'personalverwaltung', 'lehrgaenge', 'fahrtenbuch', 'uebungsdienste'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $this->travelTo(Carbon::parse('2027-11-20 12:00', 'Europe/Berlin'));

    $this->chief = User::factory()->create(['name' => 'Wilma Wehrführer', 'joined_at' => '1999-02-01']);
    $this->chief->moduleAccess()->create(['module' => 'einsatzbericht', 'role' => 'wehrführung']);
    $this->leader = User::factory()->create(['name' => 'Bernd Blaulicht', 'joined_at' => '2010-01-01']);
    $this->leader->moduleAccess()->create(['module' => 'einsatzbericht', 'role' => 'einsatzleiter']);
    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant', 'joined_at' => '2027-03-01']);
    $this->active = Group::create(['name' => 'Einsatzabteilung', 'sort_order' => 1]);
    foreach ([$this->chief, $this->leader, $this->hanna] as $u) {
        $u->groups()->attach($this->active);
    }
    $this->hlf = Vehicle::factory()->create(['name' => 'HLF 20', 'is_active' => true]);
});

test('the Jahresbericht brings together what every switched-on module knows about the year', function () {
    // Einsatz: closed and released
    $reports = app(ReportService::class);
    $report = $reports->create($this->leader, Carbon::parse('2027-05-10 19:12', 'Europe/Berlin'));
    $reports->update($this->leader, $report, ['keyword' => 'FEU 2', 'address' => 'Dorfstraße 1, Musterstadt', 'category' => 'brand_mittel',
        'ended_at' => Carbon::parse('2027-05-10 21:12', 'Europe/Berlin'), 'commander_id' => $this->leader->id]);
    $reports->assign($this->leader, $reports->addVehicle($this->leader, $report, $this->hlf->id), $this->hanna);
    $reports->close($this->leader, $report->refresh());
    $reports->release($this->chief, $report->refresh());

    // Dienste with attendance, a Lehrgang, a trip, the career
    $events = app(EventService::class);
    $dienst = $events->create(['title' => 'Übung', 'type' => 'uebung', 'starts_at' => Carbon::parse('2027-06-01 19:00', 'Europe/Berlin'), 'ends_at' => Carbon::parse('2027-06-01 21:00', 'Europe/Berlin')], [], null)->first();
    $events->setAttendance($dienst, $this->hanna, true, $this->chief);
    $course = Course::create(['name' => 'Truppmann Teil 1']);
    Record::create(['user_id' => $this->hanna->id, 'course_id' => $course->id, 'status' => 'completed', 'completed_on' => '2027-09-12']);
    Trip::create(['vehicle_id' => $this->hlf->id, 'driver_id' => $this->leader->id, 'started_at' => now()->subMonth(), 'ended_at' => now()->subMonth()->addHour(), 'start_km' => 1000, 'end_km' => 1042, 'origin' => 'Gerätehaus', 'destination' => 'Übungsplatz', 'purpose' => 'uebung']);
    $personnel = app(PersonnelService::class);
    foreach ([$this->chief, $this->leader, $this->hanna] as $u) {
        $personnel->syncHistory($u);
    }
    $personnel->saveHonorType(User::factory()->admin()->create(), null, 'Ehrenzeichen 25 Jahre', 25);

    $html = $this->actingAs($this->chief)->get(route('einsatzbericht.annual', ['jahr' => 2027]))->assertOk()->getContent();
    expect($html)->toContain('Jahresbericht 2027')
        ->toContain('<h2>Einsätze</h2>')->toContain('Brand – Mittelbrand')
        ->toContain('<h2>Mitglieder</h2>')->toContain('Hanna Hydrant')
        ->toContain('<h2>Dienste und Termine</h2>')->toContain('Teilnahmen')
        ->toContain('<h2>Aus- und Fortbildung</h2>')->toContain('Truppmann Teil 1')
        ->toContain('<h2>Fahrten</h2>')->toContain('>42<')
        ->not->toContain('Löschwasserversorgung');   // Wasserkarte is off

    $this->actingAs($this->leader)->get(route('einsatzbericht.annual'))->assertForbidden();
    $this->actingAs($this->chief)->get(route('einsatzbericht.statistics'))->assertOk()->assertSee('Jahresbericht 2027');
});

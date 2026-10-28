<?php

use App\Livewire\Profile\Hours;
use App\Models\Module;
use App\Models\User;
use App\Services\HoursRegistry;
use Extensions\Locations\Models\Location;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Modules\Einsatzbericht\src\Services\ReportService;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Kalender\src\Services\EventService;
use Modules\Lehrgaenge\src\Models\Course;
use Modules\Lehrgaenge\src\Models\Record;
use Modules\Personalverwaltung\src\Livewire\HoursOverview;
use Modules\Schichtplan\src\Models\Assignment;
use Modules\Schichtplan\src\Models\Plan;
use Modules\Schichtplan\src\Models\Slot;
use Modules\Schichtplan\src\Models\Station;

beforeEach(function () {
    config(['locations.enabled' => false]);
    Module::whereIn('key', ['kalender', 'einsatzbericht', 'lehrgaenge', 'schichtplan', 'personalverwaltung'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $this->travelTo(Carbon::parse('2027-06-20 12:00', 'Europe/Berlin'));
    $this->chief = User::factory()->create();
    $this->chief->moduleAccess()->create(['module' => 'kalender', 'role' => 'terminverwalter']);
    $this->chief->moduleAccess()->create(['module' => 'einsatzbericht', 'role' => 'einsatzleiter']);
    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant']);
    joinIncidentDepartment($this->hanna);
    foreach (['kalender', 'einsatzbericht', 'lehrgaenge', 'schichtplan', 'personalverwaltung'] as $m) {
        $this->hanna->moduleAccess()->create(['module' => $m]);
    }

    // Übung 19–21 Uhr, present
    $events = app(EventService::class);
    $event = $events->create(['title' => 'Übungsdienst', 'type' => 'uebung', 'starts_at' => Carbon::parse('2027-06-01 19:00', 'Europe/Berlin'),
        'ends_at' => Carbon::parse('2027-06-01 21:00', 'Europe/Berlin'), 'partners' => 'FF Nachbarstadt'], [], $this->chief)->first();
    $events->setAttendance($event, $this->hanna, true, $this->chief);
    $this->event = $event;

    // Einsatz 1:30 h, closed
    $reports = app(ReportService::class);
    $report = $reports->create($this->chief, Carbon::parse('2027-06-05 10:00', 'Europe/Berlin'));
    $reports->update($this->chief, $report, ['keyword' => 'TH 1', 'address' => 'Dorfstraße 1, Musterstadt', 'category' => 'th',
        'ended_at' => Carbon::parse('2027-06-05 11:30', 'Europe/Berlin'), 'commander_id' => $this->chief->id]);
    $reports->assign($this->chief, $reports->addVehicle($this->chief, $report, Vehicle::factory()->create(['is_active' => true])->id), $this->hanna);
    $reports->close($this->chief, $report->refresh());

    // Lehrgangstag 08:00–16:30
    $record = Record::create(['user_id' => $this->hanna->id, 'course_id' => Course::create(['name' => 'Maschinist'])->id, 'status' => 'completed', 'completed_on' => '2027-06-12']);
    $record->sessions()->create(['date' => '2027-06-12', 'starts_at' => '08:00', 'ends_at' => '16:30', 'location' => 'FTZ Musterkreis']);

    // Schicht 14–16 Uhr on the Dorffest
    $plan = Plan::create(['title' => 'Dorffest', 'starts_at' => Carbon::parse('2027-06-13 14:00', 'Europe/Berlin'), 'ends_at' => Carbon::parse('2027-06-13 18:00', 'Europe/Berlin'),
        'shift_minutes' => 120, 'status' => Plan::PUBLISHED, 'ext_location_id' => Location::default()->getKey()]);
    $slot = Slot::create(['plan_id' => $plan->id, 'starts_at' => Carbon::parse('2027-06-13 14:00', 'Europe/Berlin'), 'ends_at' => Carbon::parse('2027-06-13 16:00', 'Europe/Berlin')]);
    $station = Station::create(['plan_id' => $plan->id, 'name' => 'Kasse', 'capacity' => 1]);
    Assignment::create(['plan_id' => $plan->id, 'slot_id' => $slot->id, 'station_id' => $station->id, 'user_id' => $this->hanna->id]);
});

test('the Stundennachweis collects Dienste, Einsätze, Lehrgangstage and Schichten by itself', function () {
    $rows = app(HoursRegistry::class)->forUser($this->hanna, ...Hours::period(2027));
    expect($rows->groupBy('source')->map->sum('minutes')->all())->toEqual([
        'Dienste und Termine' => 120, 'Einsätze' => 90, 'Aus- und Fortbildung' => 510, 'Schichten' => 120,
    ]);

    $this->actingAs($this->hanna)->get(route('hours'))->assertOk()->assertSee('14 h')->assertSee('Lehrgang: Maschinist')->assertSee('Dorffest · Kasse');
    Livewire::actingAs($this->hanna)->test(Hours::class)->set('source', 'Einsätze')->assertSee('TH 1')->assertDontSee('Dorffest');
    $csv = $this->get(route('hours.csv', ['jahr' => 2027]))->assertOk()->getContent();
    expect($csv)->toContain('Name;Datum;Herkunft;Was;Stunden')->toContain('"Hanna Hydrant";12.06.2027;"Aus- und Fortbildung";"Lehrgang: Maschinist";8,5');
    $this->get(route('profile'))->assertSee(route('hours'));

    // Not present, other year or a module switched off: not counted
    expect(app(HoursRegistry::class)->forUser($this->hanna, ...Hours::period(2026)))->toHaveCount(0);
    Module::where('key', 'schichtplan')->update(['is_active' => false]);
    Cache::forget('modules_active_keys');
    expect(app(HoursRegistry::class)->forUser($this->hanna, ...Hours::period(2027))->sum('minutes'))->toBe(720);
});

test('the Personalverwaltung sees everyone\'s hours; a gemeinsame Übung names the partners', function () {
    $pv = User::factory()->create();
    $pv->moduleAccess()->create(['module' => 'personalverwaltung', 'role' => 'personalverwalter']);
    Livewire::actingAs($pv)->test(HoursOverview::class)->assertSee('Hanna Hydrant')->assertSee('14 h');
    $this->actingAs($pv)->get(route('personalverwaltung.member', $this->hanna))->assertSee('Stunden 2027: 14 h');
    $this->actingAs($pv)->get(route('personalverwaltung.hours.csv', ['jahr' => 2027]))->assertOk()->assertSee('Dorffest', false);
    $this->actingAs($this->hanna)->get(route('personalverwaltung.hours'))->assertForbidden();

    $this->actingAs($this->hanna)->get(route('kalender.show', $this->event))->assertSee('Gemeinsam mit FF Nachbarstadt');
});

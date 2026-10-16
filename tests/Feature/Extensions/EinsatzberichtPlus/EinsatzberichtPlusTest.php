<?php

use App\Models\Module;
use App\Models\User;
use Extensions\EinsatzberichtPlus\Livewire\Extras;
use Extensions\EinsatzberichtPlus\Models\EarningLoss;
use Extensions\EinsatzberichtPlus\Models\InvolvedPerson;
use Extensions\EinsatzberichtPlus\Models\ReportExtra;
use Extensions\EinsatzberichtPlus\Support\CrewSize;
use Extensions\Locations\Models\Location;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Einsatzbericht\src\Livewire\ReportEditor;
use Modules\Einsatzbericht\src\Services\ReportService;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

beforeEach(function () {
    Module::where('key', 'einsatzbericht')->update(['is_active' => true]);
    Module::where('key', 'fahrzeuge')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $this->travelTo(Carbon::parse('2027-05-10 20:00', 'Europe/Berlin'));

    $this->leader = User::factory()->create(['name' => 'Bernd Blaulicht', 'is_admin' => true]);
    $this->leader->moduleAccess()->create(['module' => 'einsatzbericht', 'role' => 'einsatzleiter']);
    $this->member = User::factory()->create(['name' => 'Hanna Hydrant']);
    $this->member->moduleAccess()->create(['module' => 'einsatzbericht']);
    $this->hlf = Vehicle::factory()->create(['name' => 'HLF 20', 'is_active' => true]);

    $this->r = app(ReportService::class);
    $this->report = $this->r->create($this->leader, Carbon::parse('2027-05-10 19:12', 'Europe/Berlin'));
    $this->rv = $this->r->addVehicle($this->leader, $this->report, $this->hlf->id);
});

it('computes the expected crew from the Besatzung entry', function () {
    expect(CrewSize::expected('1/8'))->toBe(9)
        ->and(CrewSize::expected('1/5/6'))->toBe(6)
        ->and(CrewSize::expected('6'))->toBe(6)
        ->and(CrewSize::expected('1/2/6/9'))->toBe(9)
        ->and(CrewSize::expected(null))->toBeNull()
        ->and(CrewSize::expected('-'))->toBeNull();
});

it('classifies the crew as empty, under, ok or over without blocking', function () {
    expect(CrewSize::check(0, 6)['state'])->toBe('empty')
        ->and(CrewSize::check(4, 6)['state'])->toBe('under')
        ->and(CrewSize::check(6, 6)['state'])->toBe('ok')
        ->and(CrewSize::check(7, 6)['state'])->toBe('over')
        ->and(CrewSize::check(3, null)['state'])->toBe('unknown');
});

it('shows under- and over-filled crews in the editor but still lets the report be edited', function () {
    DB::table('fv_profiles')->insert(['vehicle_id' => $this->hlf->id, 'crew' => '1/2', 'created_at' => now(), 'updated_at' => now()]);
    $this->r->assign($this->leader, $this->rv, $this->leader, 'gruppenfuehrer');

    $this->actingAs($this->leader);
    Livewire::test(ReportEditor::class, ['report' => $this->report])
        ->assertSeeHtml('data-crew-check="under"')
        ->assertSee('unter Sollbesatzung');

    foreach (['Ida Impuls', 'Jonas Jet', 'Karla Kübel'] as $name) {
        $this->r->assign($this->leader, $this->rv, User::factory()->create(['name' => $name]));
    }
    Livewire::test(ReportEditor::class, ['report' => $this->report->refresh()])
        ->assertSeeHtml('data-crew-check="over"')
        ->assertSee('über Sollbesatzung');
});

it('keeps breathing apparatus optional per person', function () {
    $crew = $this->r->assign($this->leader, $this->rv, $this->member, 'angriffstrupp');
    expect($crew->breathing_apparatus)->toBeFalse();
});

it('saves the cost-bearing flag', function () {
    $this->actingAs($this->leader);
    Livewire::test(Extras::class, ['reportId' => $this->report->id])
        ->set('chargeable', true)->set('chargeableTo', 'Musterbau GmbH')->set('chargeableNote', 'Ölspur')
        ->call('saveCharge');

    $extra = ReportExtra::where('report_id', $this->report->id)->first();
    expect($extra->chargeable)->toBeTrue()->and($extra->chargeable_to)->toBe('Musterbau GmbH')->and($extra->chargeable_note)->toBe('Ölspur');
    expect(DB::table('x_ebp_extras')->value('chargeable_note'))->not->toBe('Ölspur');
});

it('stores involved persons encrypted and removes them', function () {
    $this->actingAs($this->leader);
    Livewire::test(Extras::class, ['reportId' => $this->report->id])
        ->set('person', ['role' => 'geschaedigt', 'name' => 'Paula Pegel', 'contact' => 'Dorfstraße 1, Musterstadt', 'note' => ''])
        ->call('addPerson')
        ->assertSee('Paula Pegel')->assertSee('Geschädigte Person');

    $raw = DB::table('x_ebp_persons')->first();
    expect($raw->name)->not->toContain('Paula')->and($raw->contact)->not->toContain('Dorfstraße');

    $person = InvolvedPerson::first();
    Livewire::test(Extras::class, ['reportId' => $this->report->id])->call('removePerson', $person->id);
    expect(InvolvedPerson::count())->toBe(0);
});

it('validates the person name and role', function () {
    $this->actingAs($this->leader);
    Livewire::test(Extras::class, ['reportId' => $this->report->id])
        ->set('person', ['role' => 'kaiser', 'name' => '', 'contact' => '', 'note' => ''])
        ->call('addPerson')->assertHasErrors(['person.role', 'person.name']);
});

it('tracks Verdienstausfall only for the crew of the report', function () {
    $this->r->assign($this->leader, $this->rv, $this->member, 'angriffstrupp');
    $outsider = User::factory()->create(['name' => 'Otto Außen']);

    $this->actingAs($this->leader);
    Livewire::test(Extras::class, ['reportId' => $this->report->id])
        ->set('loss', ['user' => (string) $outsider->id, 'employer' => 'Beispiel AG', 'hours' => '3,5', 'note' => ''])
        ->call('addLoss')->assertHasErrors(['loss.user']);

    Livewire::test(Extras::class, ['reportId' => $this->report->id])
        ->set('loss', ['user' => (string) $this->member->id, 'employer' => 'Beispiel AG', 'hours' => '3,5', 'note' => ''])
        ->call('addLoss')->assertHasNoErrors()->assertSee('Hanna Hydrant');

    $loss = EarningLoss::first();
    expect($loss->status)->toBe('open')->and((float) $loss->hours)->toBe(3.5)->and($loss->employer)->toBe('Beispiel AG');
    expect(DB::table('x_ebp_earning_losses')->value('employer'))->not->toBe('Beispiel AG');

    Livewire::test(Extras::class, ['reportId' => $this->report->id])->call('setLossStatus', $loss->id, 'requested');
    expect($loss->refresh()->status)->toBe('requested');
    Livewire::test(Extras::class, ['reportId' => $this->report->id])->call('setLossStatus', $loss->id, 'bogus')->assertStatus(422);
});

it('adds an external vehicle as free text', function () {
    $this->actingAs($this->leader);
    Livewire::test(Extras::class, ['reportId' => $this->report->id])
        ->set('externalVehicle', 'TLF 3000 FF Beispieldorf')->call('addExternalVehicle')
        ->assertRedirect(route('einsatzbericht.show', $this->report));

    $vehicle = $this->report->vehicles()->whereNull('vehicle_id')->first();
    expect($vehicle->name)->toBe('TLF 3000 FF Beispieldorf')->and($vehicle->is_standby)->toBeFalse();
});

it('refuses users without the Einsatzleiter role', function () {
    $this->actingAs($this->member);
    Livewire::test(Extras::class, ['reportId' => $this->report->id])->assertForbidden();
});

it('refuses editing a released report for the Einsatzleiter', function () {
    $this->report->forceFill(['status' => 'released'])->save();
    $leader = User::factory()->create(['name' => 'Egon Einsatz']);
    $leader->moduleAccess()->create(['module' => 'einsatzbericht', 'role' => 'einsatzleiter']);

    $this->actingAs($leader);
    Livewire::test(Extras::class, ['reportId' => $this->report->id])
        ->set('person', ['role' => 'zeuge', 'name' => 'Zora Zeuge', 'contact' => '', 'note' => ''])
        ->call('addPerson')->assertForbidden();
    expect(InvolvedPerson::count())->toBe(0);
});

it('does not reach reports of another Standort', function () {
    $a = Location::create(['name' => 'Wache A', 'slug' => 'wache-a']);
    $b = Location::create(['name' => 'Wache B', 'slug' => 'wache-b']);
    $this->report->forceFill(['ext_location_id' => $b->id])->save();

    $keeper = User::factory()->create(['name' => 'Lena Leitung']);
    $keeper->moduleAccess()->create(['module' => 'einsatzbericht', 'role' => 'einsatzleiter']);
    $keeper->forceFill(['ext_location_id' => $a->id])->save();
    DB::table('x_location_user')->insert(['location_id' => $a->id, 'user_id' => $keeper->id, 'role' => 'member']);

    $this->actingAs($keeper);
    Livewire::test(Extras::class, ['reportId' => $this->report->id])->assertNotFound();
});

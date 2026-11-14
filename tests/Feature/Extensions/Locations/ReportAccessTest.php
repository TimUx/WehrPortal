<?php

use App\Models\Module;
use App\Models\User;
use App\Services\ModuleRegistry;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\StandardRoleAssignments;
use Extensions\Locations\Services\StandardRoleCatalog;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Einsatzbericht\src\Livewire\MemberMissions;
use Modules\Einsatzbericht\src\Livewire\Members;
use Modules\Einsatzbericht\src\Livewire\ReportList;
use Modules\Einsatzbericht\src\Livewire\Statistics;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Einsatzbericht\src\Services\ReportService;

beforeEach(function () {
    config(['locations.enabled' => true]);
    Module::where('key', 'einsatzbericht')->update(['is_active' => true, 'auto_assign' => true]);
    app(ModuleRegistry::class)->clearCache();

    $this->admin = User::factory()->admin()->create();
    $this->north = Location::create(['name' => 'Wache Nord', 'slug' => 'eb-nord']);
    $this->south = Location::create(['name' => 'Wache Süd', 'slug' => 'eb-sued']);
    $at = function (Location $location, string $name): User {
        $user = User::factory()->create(['name' => $name]);
        $user->forceFill(['ext_location_id' => $location->id])->save();

        return $user;
    };
    $this->hanna = $at($this->north, 'Hanna Hydrant');   // Wehrführung Nord
    $this->bernd = $at($this->north, 'Bernd Blaulicht'); // writes the report
    $this->frieda = $at($this->north, 'Frieda Funk');    // another Kamerad
    $this->sven = $at($this->south, 'Sven Süd');         // Wehrführung Süd
    $roles = app(StandardRoleAssignments::class);
    $roles->grant($this->admin, $this->hanna->id, 'wehrfuehrung', $this->north->id, null, 'Wehrführerin Nord');
    $roles->grant($this->admin, $this->sven->id, 'wehrfuehrung', $this->south->id, null, 'Wehrführer Süd');
});

it('lets every Kamerad write a report that only the Wehrführung of the Standort reads afterwards', function () {
    $this->actingAs($this->bernd)->get(route('einsatzbericht.index'))->assertOk()->assertSee('Einsatzbericht schreiben');
    $this->actingAs($this->bernd)->post(route('einsatzbericht.create'))->assertRedirect();
    $report = Report::withoutGlobalScopes()->where('created_by', $this->bernd->id)->sole();

    expect($report->ext_location_id)->toBe($this->north->id)
        ->and(ReportService::canLead($this->bernd))->toBeFalse()
        ->and(ReportService::canEdit($this->bernd, $report))->toBeTrue()
        ->and(ReportService::canReadReport($this->bernd, $report))->toBeTrue()
        // Not the other Kameraden, not the Wehrführung of another Standort
        ->and(ReportService::canReadReport($this->frieda, $report))->toBeFalse()
        ->and(ReportService::canReadReport($this->sven, $report))->toBeFalse()
        ->and(ReportService::canReadReport($this->hanna, $report))->toBeTrue();

    $this->actingAs($this->bernd)->get(route('einsatzbericht.show', $report))->assertOk();
    $this->actingAs($this->bernd)->get(route('einsatzbericht.index'))->assertSee('Bericht weiterschreiben');
    $this->actingAs($this->frieda)->get(route('einsatzbericht.show', $report))->assertForbidden();
    Livewire::actingAs($this->bernd)->test(ReportList::class)->assertForbidden();

    // Closed: handed over, the author is out
    $report->forceFill(['status' => 'closed'])->save();
    expect(ReportService::canReadReport($this->bernd, $report))->toBeFalse()
        ->and(ReportService::canEdit($this->bernd, $report))->toBeFalse()
        ->and(ReportService::canReleaseReport($this->hanna, $report))->toBeTrue();
    $this->actingAs($this->bernd)->get(route('einsatzbericht.show', $report))->assertForbidden();
    $this->actingAs($this->bernd)->get(route('einsatzbericht.index'))->assertDontSee('Bericht weiterschreiben');
    $this->actingAs($this->hanna)->get(route('einsatzbericht.show', $report))->assertOk();
});

it('lets the role Einsicht read the reports of every Standort without changing them', function () {
    $north = app(ReportService::class)->create($this->bernd);
    $south = app(ReportService::class)->create($this->sven);
    // The crew belongs to a Standort the reader is no member of: its names are part of the report
    $vehicle = $south->vehicles()->create(['name' => 'HLF Süd', 'sort_order' => 1]);
    $south->crew()->create(['report_vehicle_id' => $vehicle->id, 'user_id' => $this->sven->id, 'function' => 'gruppenfuehrer']);
    $reader = User::factory()->create(['name' => 'Berta Brandschutz']);
    $reader->forceFill(['ext_location_id' => $this->north->id])->save();
    $reader->moduleAccess()->create(['module' => 'einsatzbericht', 'role' => 'einsicht']);

    expect(ReportService::isReader($reader))->toBeTrue()
        ->and(ReportService::canLead($reader))->toBeFalse()
        ->and(ReportService::canReadReport($reader, $south))->toBeTrue()
        ->and(ReportService::canEdit($reader, $south))->toBeFalse()
        ->and(ReportService::canReleaseReport($reader, $south))->toBeFalse()
        ->and(ReportService::isReader($this->frieda))->toBeFalse();

    $this->actingAs($reader);
    expect(Report::pluck('id')->all())->toEqualCanonicalizing([$north->id, $south->id]);
    $this->get(route('einsatzbericht.show', $south))->assertOk()->assertSee('Sven Süd');
    // A finished Einsatz offers the reader no Lagemeldung form
    $south->forceFill(['status' => 'released', 'ended_at' => now()])->save();
    $this->get(route('einsatzbericht.show', $south))->assertOk()->assertDontSee('Lage kurz beschreiben');
    $south->forceFill(['status' => 'active', 'ended_at' => null])->save();
    $this->get(route('einsatzbericht.print', $south))->assertOk()->assertSee('Sven Süd');
    Livewire::actingAs($reader)->test(ReportList::class)->assertOk();
    $this->get(route('einsatzbericht.statistics'))->assertForbidden();
});

it('gives the Brandschutzamt the role Einsicht', function () {
    expect(app(StandardRoleCatalog::class)->hasModuleRole('brandschutzamt', 'einsatzbericht', 'einsicht'))->toBeTrue();
});

it('lets a Kamerad take over a report that came from the alarm, once', function () {
    $report = app(ReportService::class)->createFromAlarm(['keyword' => 'F2 Wohnung', 'ext_location_id' => $this->north->id], now());

    expect(ReportService::canClaim($this->bernd, $report))->toBeTrue()
        ->and(ReportService::canReadReport($this->bernd, $report))->toBeFalse();
    $this->actingAs($this->bernd)->get(route('einsatzbericht.index'))->assertSee('Bericht übernehmen')->assertSee('F2 Wohnung');
    // Reports of another Standort are not offered
    $this->actingAs($this->sven)->get(route('einsatzbericht.index'))->assertDontSee('F2 Wohnung');

    $this->actingAs($this->bernd)->post(route('einsatzbericht.claim', $report))->assertRedirect(route('einsatzbericht.show', $report));
    expect($report->fresh()->created_by)->toBe($this->bernd->id)
        ->and(ReportService::canEdit($this->bernd, $report->fresh()))->toBeTrue();

    // Taken: nobody else gets it
    expect(fn () => app(ReportService::class)->claim($this->frieda, $report->fresh()))->toThrow(ValidationException::class);
    expect(ReportService::canReadReport($this->frieda, $report->fresh()))->toBeFalse();
});

it('gives the old Einsatzleiter role no rights any more', function () {
    $report = app(ReportService::class)->create($this->bernd);
    $this->frieda->moduleAccess()->updateOrCreate(['module' => 'einsatzbericht'], ['role' => 'einsatzleiter']);

    expect(ReportService::canLead($this->frieda->fresh()))->toBeFalse()
        ->and(ReportService::canReadReport($this->frieda->fresh(), $report))->toBeFalse();
});

it('shows the Wehrführung of a Standort the Statistik and the Kameraden of its own Standort only', function () {
    $released = function (User $author, string $keyword): Report {
        $report = app(ReportService::class)->create($author);
        $vehicle = $report->vehicles()->create(['name' => 'HLF', 'sort_order' => 1]);
        $report->crew()->create(['report_vehicle_id' => $vehicle->id, 'user_id' => $author->id, 'function' => 'gruppenfuehrer']);
        $report->forceFill(['status' => 'released', 'keyword' => $keyword, 'ext_location_id' => $author->ext_location_id, 'alarmed_at' => now()->subHour(), 'ended_at' => now()])->save();

        return $report;
    };
    $released($this->bernd, 'Brand Nord');
    $released($this->bernd, 'Hilfe Nord');
    $released($this->sven, 'Brand Süd');

    $this->actingAs($this->hanna)->get(route('einsatzbericht.statistics'))->assertOk();
    $this->actingAs($this->hanna)->get(route('einsatzbericht.members'))->assertOk();
    // The Jahresbericht of all modules stays with the Wehrführung of the whole Kommune
    $this->actingAs($this->hanna)->get(route('einsatzbericht.annual'))->assertForbidden();
    $this->actingAs($this->bernd)->get(route('einsatzbericht.statistics'))->assertForbidden();
    $this->actingAs($this->bernd)->get(route('einsatzbericht.members'))->assertForbidden();

    Livewire::actingAs($this->hanna)->test(Statistics::class)
        ->assertViewHas('stats', fn (array $stats) => $stats['total'] === 2)
        ->assertViewHas('annual', false)
        ->assertDontSee('Jahresbericht');
    Livewire::actingAs($this->hanna)->test(Members::class)
        ->assertSee('Bernd Blaulicht')->assertSee('2 gesamt')
        ->assertDontSee('Sven Süd');

    $this->actingAs($this->hanna)->get(route('einsatzbericht.member', $this->bernd))->assertOk()->assertSee('Brand Nord');
    $this->actingAs($this->hanna)->get(route('einsatzbericht.member', $this->sven))->assertForbidden();
    Livewire::actingAs($this->hanna)->test(MemberMissions::class, ['member' => $this->sven])->assertForbidden();

    // An admin still counts everything
    Livewire::actingAs($this->admin)->test(Statistics::class)
        ->assertViewHas('stats', fn (array $stats) => $stats['total'] === 3)
        ->assertViewHas('annual', true);
    Livewire::actingAs($this->admin)->test(Members::class)->assertSee('Sven Süd');
});

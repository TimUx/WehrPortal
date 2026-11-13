<?php

use App\Models\Module;
use App\Models\User;
use App\Services\ModuleRegistry;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\StandardRoleAssignments;
use Extensions\Locations\Services\StandardRoleCatalog;
use Livewire\Livewire;
use Modules\Einsatzbericht\src\Livewire\ReportList;
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
    $this->get(route('einsatzbericht.show', $south))->assertOk();
    $this->get(route('einsatzbericht.print', $south))->assertOk();
    Livewire::actingAs($reader)->test(ReportList::class)->assertOk();
    $this->get(route('einsatzbericht.statistics'))->assertForbidden();
});

it('gives the Brandschutzamt the role Einsicht', function () {
    expect(app(StandardRoleCatalog::class)->hasModuleRole('brandschutzamt', 'einsatzbericht', 'einsicht'))->toBeTrue();
});

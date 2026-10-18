<?php

use App\Models\Module;
use App\Models\User;
use App\Services\ModuleRegistry;
use Extensions\Hydrantenkontrolle\Livewire\HydrantForm;
use Extensions\Hydrantenkontrolle\Livewire\YearOverview;
use Extensions\Hydrantenkontrolle\Models\Hydrant;
use Extensions\Hydrantenkontrolle\Models\Inspection;
use Extensions\Hydrantenkontrolle\Services\InspectionService;
use Extensions\Locations\Models\Location;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    Module::where('key', 'hydrantenkontrolle')->update(['is_active' => true]);
    Cache::flush();
    $this->wart = User::factory()->create(['name' => 'Hanna Hydrant']);
    $this->wart->moduleAccess()->create(['module' => 'hydrantenkontrolle', 'role' => 'hydrantenwart']);
    $this->actingAs($this->wart);
});

it('registers navigation and renders the German module pages', function () {
    expect(app(ModuleRegistry::class)->get('hydrantenkontrolle')['roles'])->toBe(['hydrantenwart']);
    $this->get(route('hydrantenkontrolle.index'))->assertOk()->assertSee('Jahreskontrollen');
    $this->get(route('hydrantenkontrolle.hydrants'))->assertOk()->assertSee('Hydrant erfassen');
    $this->get(route('hydrantenkontrolle.create'))->assertOk()->assertSee('Lagebeschreibung');
});

it('denies guests and members without the hydrantenwart role including direct Livewire access', function () {
    $member = User::factory()->create();
    $member->moduleAccess()->create(['module' => 'hydrantenkontrolle']);
    $this->actingAs($member)->get(route('hydrantenkontrolle.index'))->assertForbidden();
    Livewire::actingAs($member)->test(HydrantForm::class)->assertForbidden();
    Livewire::actingAs($member)->test(YearOverview::class)->assertForbidden();
    auth()->logout();
    $this->get(route('hydrantenkontrolle.index'))->assertRedirect();
});

it('hides the inactive module even from administrators', function () {
    Module::where('key', 'hydrantenkontrolle')->update(['is_active' => false]);
    Cache::flush();
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin)->get(route('hydrantenkontrolle.index'))->assertNotFound();
});

it('creates hydrants and resumes an inspection through Livewire without overwriting on reopen', function () {
    Livewire::test(HydrantForm::class)
        ->set('form.number', 'H-1')->set('form.street', 'Musterstraße')
        ->call('save')->assertHasNoErrors()->assertRedirect(route('hydrantenkontrolle.hydrants'));
    Livewire::test(YearOverview::class)
        ->set('newYear', '2026')->call('openYear')->assertHasNoErrors()->assertRedirect();
    $inspection = Inspection::sole();
    $year = $inspection->year;
    $this->get(route('hydrantenkontrolle.year', $year))->assertOk()->assertSee('Ungeprüft');
    Livewire::test(YearOverview::class, ['year' => $year])
        ->call('edit', $inspection->id)
        ->set('form.checked_at', '2026-10-18')
        ->set('form.defects', ['sign_problem'])->set('form.notes', 'Schild ersetzen')
        ->call('save')->assertHasNoErrors()
        ->assertSee('Mit Mängeln');
    Livewire::test(YearOverview::class, ['year' => $year])
        ->call('edit', $inspection->id)
        ->assertSet('form.notes', 'Schild ersetzen')
        ->assertSet('form.defects', ['sign_problem']);
    app(InspectionService::class)->openYear($this->wart, 2026);
    expect($inspection->fresh()->notes)->toBe('Schild ersetzen');
});

it('rejects stale Livewire inspection forms instead of overwriting a newer result', function () {
    $service = app(InspectionService::class);
    $service->saveHydrant($this->wart, ['number' => 'H-1', 'street' => 'Musterstraße']);
    $year = $service->openYear($this->wart, 2026);
    $inspection = $year->inspections()->sole();
    $editor = Livewire::test(YearOverview::class, ['year' => $year])->call('edit', $inspection->id);
    $service->saveInspection($this->wart, $inspection, [
        'checked_at' => '2026-10-18', 'is_ok' => true,
    ], $inspection->version);
    $editor->set('form.checked_at', '2026-10-19')->set('form.defects', ['sign_problem'])
        ->call('save')->assertHasErrors(['version']);
    expect($inspection->fresh()->is_ok)->toBeTrue()->and($inspection->fresh()->defects)->toBe([]);
});

it('filters annual inspections and keeps summary counts independent of the filter', function () {
    $service = app(InspectionService::class);
    $service->saveHydrant($this->wart, ['number' => 'A-1', 'street' => 'Musterstraße', 'active' => true]);
    $service->saveHydrant($this->wart, ['number' => 'B-2', 'street' => 'Beispielweg', 'active' => true]);
    $year = $service->openYear($this->wart, 2026);
    $inspection = $year->inspections()->first();
    $service->saveInspection($this->wart, $inspection, ['checked_at' => '2026-10-18', 'is_ok' => true, 'defects' => [], 'notes' => ''], $inspection->version);
    Livewire::test(YearOverview::class, ['year' => $year])
        ->set('search', 'Beispielweg')->assertSee('B-2')->assertDontSee('A-1')
        ->set('search', '')->set('status', 'ok')->assertSee('A-1')->assertDontSee('B-2')
        ->set('status', 'unchecked')->assertSee('B-2')->assertDontSee('A-1');
});

it('exports real PDF bytes with safe content headers and all annual results', function () {
    $service = app(InspectionService::class);
    $service->saveHydrant($this->wart, ['number' => 'PDF-1', 'location_description' => 'Am Musterplatz', 'active' => true]);
    $year = $service->openYear($this->wart, 2026);
    $response = $this->get(route('hydrantenkontrolle.pdf', $year))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->getContent())->toStartWith('%PDF-');
    $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

    $html = view('hydrantenkontrolle::pdf.year', [
        'year' => $year, 'inspections' => $year->inspections, 'org' => 'FF Musterstadt',
        'defects' => \Extensions\Hydrantenkontrolle\Support\Checklist::DEFECTS,
        'createdAt' => now()->timezone('Europe/Berlin'),
    ])->render();
    expect($html)->toContain('FF Musterstadt', '2026', 'PDF-1', 'Am Musterplatz', 'Ungeprüft');

    $member = User::factory()->create();
    $member->moduleAccess()->create(['module' => 'hydrantenkontrolle']);
    $this->actingAs($member)->get(route('hydrantenkontrolle.pdf', $year))->assertForbidden();
});

it('generates multiple PDF pages for larger annual inventories', function () {
    $service = app(InspectionService::class);
    for ($number = 1; $number <= 35; $number++) {
        $service->saveHydrant($this->wart, [
            'number' => 'PDF-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
            'street' => 'Musterstraße', 'house_number' => (string) $number,
        ]);
    }
    $year = $service->openYear($this->wart, 2026);
    $response = $this->get(route('hydrantenkontrolle.pdf', $year))->assertOk();
    expect(preg_match_all('~/Type\s*/Page\b~', $response->getContent()))->toBeGreaterThan(1);
});

it('protects year views PDFs and inspection editing across locations', function () {
    $south = Location::create(['name' => 'Wache Süd', 'slug' => 'hc-south']);
    $north = Location::create(['name' => 'Wache Nord', 'slug' => 'hc-north']);
    DB::table('x_location_user')->insert(['location_id' => $south->id, 'user_id' => $this->wart->id, 'role' => 'member']);
    $this->wart->forceFill(['ext_location_id' => $south->id])->save();
    auth()->logout();
    $admin = User::factory()->create(['is_admin' => true, 'ext_location_id' => $north->id]);
    $this->actingAs($admin);
    $service = app(InspectionService::class);
    $service->saveHydrant($admin, ['number' => 'N-1', 'street' => 'Beispielweg', 'active' => true]);
    $year = $service->openYear($admin, 2026);
    $hydrant = Hydrant::where('number', 'N-1')->sole();
    $this->actingAs($this->wart);
    $this->get(route('hydrantenkontrolle.year', $year))->assertNotFound();
    $this->get(route('hydrantenkontrolle.pdf', $year))->assertNotFound();
    $this->get(route('hydrantenkontrolle.edit', $hydrant))->assertNotFound();
});

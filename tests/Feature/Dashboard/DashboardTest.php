<?php

use App\Livewire\Dashboard\Home;
use App\Models\Module;
use App\Models\User;
use App\Models\UserModulePreference;
use App\Services\DashboardRegistry;
use App\Services\Impersonation;
use App\Services\ModuleOverview;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    Module::query()->update(['is_active' => false]);
    Module::whereIn('key', ['fahrzeugbuchung', 'kleiderkammer', 'maengelmanagement'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');

    $this->member = User::factory()->create(['name' => 'Hanna Hydrant', 'phone' => null]);
    foreach (['fahrzeugbuchung', 'kleiderkammer', 'maengelmanagement'] as $key) {
        $this->member->moduleAccess()->create(['module' => $key]);
    }
});

// ─── modules on the start page ───────────────────────────────────────────────

test('the start page shows the modules a Kamerad may use', function () {
    $this->actingAs($this->member)->get('/')
        ->assertOk()
        ->assertSee('Fahrzeugbuchung')->assertSee('Kleiderkammer')->assertSee('Mängelmanagement')
        ->assertDontSee('Ausrüstungsverwaltung');
});

test('a Kamerad sorts the modules and the order is kept', function () {
    $overview = app(ModuleOverview::class);
    expect(array_keys($overview->for($this->member)))->toBe(['fahrzeugbuchung', 'kleiderkammer', 'maengelmanagement']);

    Livewire::actingAs($this->member)->test(Home::class)
        ->set('editing', true)
        ->call('move', 'maengelmanagement', -1)
        ->call('move', 'maengelmanagement', -1);

    expect(array_keys($overview->for($this->member)))->toBe(['maengelmanagement', 'fahrzeugbuchung', 'kleiderkammer']);

    // Someone else keeps the default order
    $other = User::factory()->create();
    foreach (['fahrzeugbuchung', 'kleiderkammer', 'maengelmanagement'] as $key) {
        $other->moduleAccess()->create(['module' => $key]);
    }
    expect(array_keys($overview->for($other)))->toBe(['fahrzeugbuchung', 'kleiderkammer', 'maengelmanagement']);
});

test('without a lock the most used modules move up; repeated views within half an hour count once', function () {
    $overview = app(ModuleOverview::class);
    $overview->setLocked($this->member, false);

    $this->actingAs($this->member)->get('/maengelmanagement')->assertOk();
    $this->get('/maengelmanagement')->assertOk();                       // same visit
    expect(array_keys($overview->for($this->member->fresh())))->toBe(['maengelmanagement', 'fahrzeugbuchung', 'kleiderkammer']);

    // Two visits to the Kleiderkammer on different days beat one Mängel visit
    $this->travel(1)->hours();
    $this->get('/kleiderkammer');
    $this->travel(1)->days();
    $this->get('/kleiderkammer');
    expect(array_keys($overview->for($this->member->fresh())))->toBe(['kleiderkammer', 'maengelmanagement', 'fahrzeugbuchung']);

    // Livewire updates don't count
    $this->withHeader('X-Livewire', '1')->get('/fahrzeugbuchung/kalender');
    expect(array_keys($overview->for($this->member->fresh()))[2])->toBe('fahrzeugbuchung');
});

test('old use fades: the score halves every 30 days', function () {
    $overview = app(ModuleOverview::class);
    $overview->setLocked($this->member, false);
    foreach (range(1, 3) as $day) {
        $overview->recordUse($this->member, 'kleiderkammer');
        $this->travel(1)->days();
    }
    $this->travel(90)->days();
    $overview->recordUse($this->member, 'maengelmanagement');

    // 3 uses three months ago are worth less than one today
    expect(array_keys($overview->for($this->member->fresh()))[0])->toBe('maengelmanagement')
        ->and(round(array_values($overview->for($this->member->fresh()))[1]['score'], 2))->toBeLessThan(0.5);
});

test('the own order is the default; sorting by use is switched on, sorting by hand locks again, reset goes back to the default', function () {
    $overview = app(ModuleOverview::class);
    $overview->recordUse($this->member, 'maengelmanagement');

    // Default: fixed, in the standard order despite the use
    expect($this->member->fresh()->modules_locked)->toBeTrue()
        ->and(array_keys($overview->for($this->member->fresh())))->toBe(['fahrzeugbuchung', 'kleiderkammer', 'maengelmanagement']);
    Livewire::actingAs($this->member)->test(Home::class)->assertSee('eigene Reihenfolge')
        ->set('editing', true)->assertSee('Reihenfolge fixiert')
        ->call('toggleLock')->assertSee('Automatisch nach Nutzung sortieren');
    expect(array_keys($overview->for($this->member->fresh()))[0])->toBe('maengelmanagement');

    Livewire::actingAs($this->member->fresh())->test(Home::class)
        ->assertSee('nach Nutzung')
        ->set('editing', true)
        ->call('move', 'kleiderkammer', -1)
        ->assertSee('Reihenfolge fixiert');

    $locked = array_keys($overview->for($this->member->fresh()));
    expect($this->member->fresh()->modules_locked)->toBeTrue();

    // Use no longer changes the locked order
    foreach (range(1, 5) as $i) {
        $overview->recordUse($this->member, 'fahrzeugbuchung');
        $this->travel(1)->hours();
    }
    expect(array_keys($overview->for($this->member->fresh())))->toBe($locked);

    Livewire::actingAs($this->member->fresh())->test(Home::class)->call('toggleLock');
    expect($this->member->fresh()->modules_locked)->toBeFalse()
        ->and(array_keys($overview->for($this->member->fresh()))[0])->toBe('fahrzeugbuchung');

    Livewire::actingAs($this->member->fresh())->test(Home::class)->call('resetOrder');
    expect($this->member->fresh()->modules_locked)->toBeTrue()
        ->and(array_keys($overview->for($this->member->fresh())))->toBe(['fahrzeugbuchung', 'kleiderkammer', 'maengelmanagement']);
});

test('an admin looking as the Kamerad does not change the Kamerad\'s use', function () {
    $this->actingAs($this->member)->withSession([Impersonation::SESSION_KEY => User::factory()->create(['is_admin' => true])->id])
        ->get('/maengelmanagement')->assertOk();

    expect(UserModulePreference::where('user_id', $this->member->id)->exists())->toBeFalse();
});

test('a hidden module disappears from the start page but stays in the launcher', function () {
    Livewire::actingAs($this->member)->test(Home::class)
        ->set('editing', true)
        ->call('toggleHidden', 'kleiderkammer')
        ->set('editing', false)
        ->assertDontSee('Verwaltung der Bekleidungsausgabe')
        ->assertSee('1 ausgeblendet');

    // The launcher in the header still lists it
    $this->actingAs($this->member)->get('/')->assertSee('Alle Module')->assertSee(route('kleiderkammer.index'));

    Livewire::actingAs($this->member)->test(Home::class)->call('resetOrder')->assertSee('Verwaltung der Bekleidungsausgabe');
});

test('the launcher is on every app page and lists only the own modules', function () {
    $this->actingAs($this->member)->get('/personalakte')
        ->assertSee('Alle Module')
        ->assertSee(route('fahrzeugbuchung.calendar'))
        ->assertDontSee('/ausruestung');
});

test('modules put their cards on the start page while active and allowed', function () {
    app(DashboardRegistry::class)->register('kleiderkammer', 'test', fn (User $u) => '<div>Karte für '.e($u->name).'</div>');
    app(DashboardRegistry::class)->register('ausruestung', 'test', fn () => '<div>Ausrüstungskarte</div>');

    Livewire::actingAs($this->member)->test(Home::class)
        ->assertSee('Karte für Hanna Hydrant', false)
        ->assertDontSee('Ausrüstungskarte');
});

test('dragging sends the new order and unknown or missing keys are handled', function () {
    Livewire::actingAs($this->member)->test(Home::class)
        ->set('editing', true)
        ->call('reorder', ['kleiderkammer', 'gibt-es-nicht', 'maengelmanagement']);

    expect(array_keys(app(ModuleOverview::class)->for($this->member)))->toBe(['kleiderkammer', 'maengelmanagement', 'fahrzeugbuchung']);
});

test('the sort list shows every module with a switch, also hidden ones', function () {
    app(ModuleOverview::class)->setHidden($this->member, 'kleiderkammer', true);

    Livewire::actingAs($this->member)->test(Home::class)
        ->set('editing', true)
        ->assertSee('Kleiderkammer auf der Startseite zeigen')
        ->assertSeeHtml('aria-checked="false"')
        ->assertSeeHtml('data-module="kleiderkammer"');
});

test('the launcher lists the modules in the own order and marks the current one', function () {
    app(ModuleOverview::class)->reorder($this->member, ['maengelmanagement', 'fahrzeugbuchung', 'kleiderkammer']);

    $html = $this->actingAs($this->member)->get(route('kleiderkammer.index'))->assertOk()->getContent();
    $launcher = substr($html, strpos($html, 'Alle Module'));

    expect(strpos($launcher, 'Mängelmanagement'))->toBeLessThan(strpos($launcher, 'Fahrzeugbuchung'))
        ->and(strpos($launcher, 'Fahrzeugbuchung'))->toBeLessThan(strpos($launcher, 'Kleiderkammer'))
        ->and($launcher)->toContain('bg-fw-navy text-white');
});

test('on the phone Profil and Admin are in the 3×3 menu; modules have the Modulleiste', function () {
    $admin = User::factory()->admin()->create(['name' => 'Anna Admin']);

    $this->actingAs($admin)->get(route('home'))->assertOk()
        ->assertSee('data-launcher-profile', false)
        ->assertSee('href="'.route('filament.admin.pages.dashboard').'" class="sm:hidden', false)
        ->assertDontSee('fixed bottom-0', false);
    $this->actingAs($this->member)->get(route('home'))->assertOk()
        ->assertSee('data-launcher-profile', false)->assertDontSee('data-launcher-admin', false);

    // Inside a module the Modulleiste holds the tabs; there is no bar at the bottom any more
    $this->actingAs($admin)->get(route('fahrzeugbuchung.calendar'))->assertOk()
        ->assertSee('data-module-bar', false)->assertDontSee('fixed bottom-0', false);
});

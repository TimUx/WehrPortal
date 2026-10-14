<?php

use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Module::whereIn('key', ['lehrgaenge', 'schichtplan', 'ausruestung', 'personalverwaltung'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant']);
    foreach (['lehrgaenge', 'schichtplan', 'ausruestung'] as $module) {
        $this->hanna->moduleAccess()->create(['module' => $module]);
    }
    $this->bernd = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $this->bernd->moduleAccess()->create(['module' => 'lehrgaenge', 'role' => 'ausbildungsleiter']);
    $this->bernd->moduleAccess()->create(['module' => 'schichtplan', 'role' => 'planer']);
    $this->bernd->moduleAccess()->create(['module' => 'ausruestung', 'role' => 'geraetewart']);
});

test('a module shows its Modulleiste with only the tabs the Kamerad may open', function () {
    $this->actingAs($this->hanna)->get(route('lehrgaenge.index'))->assertOk()
        ->assertSee('data-module-bar', false)->assertSee('Lehrgänge')
        ->assertDontSee('href="'.route('lehrgaenge.courses').'"', false)->assertDontSee('href="'.route('lehrgaenge.registrations').'"', false)->assertDontSee('href="'.route('lehrgaenge.import').'"', false);

    $this->actingAs($this->bernd)->get(route('lehrgaenge.index'))->assertOk()
        ->assertSee('href="'.route('lehrgaenge.courses').'"', false)->assertSee('href="'.route('lehrgaenge.registrations').'"', false)->assertSee('href="'.route('lehrgaenge.import').'"', false);
});

test('the Personalakte has its own bar, not the one of the module that adds the page', function () {
    $html = $this->actingAs($this->bernd)->get(route('lehrgaenge.personnel-file'))->assertOk()->getContent();
    expect($html)->toContain('Meine Personalakte')->toContain('href="'.route('profile').'"')
        ->not->toContain('Lehrgangsliste')->not->toContain('href="'.route('lehrgaenge.import').'"');
});

test('main action and Verwaltung only for the roles, the tab of a sub page stays marked', function () {
    $this->actingAs($this->hanna)->get(route('schichtplan.index'))->assertOk()->assertDontSee('aria-label="Neuer Plan"', false);
    $this->actingAs($this->bernd)->get(route('schichtplan.create'))->assertOk()
        ->assertSee('aria-label="Neuer Plan"', false)->assertSee('href="'.route('schichtplan.index').'"', false);

    $this->actingAs($this->hanna)->get(route('ausruestung.equipment'))->assertOk()->assertDontSee('title="Verwaltung"', false)->assertDontSee('Inventur');
    $this->actingAs($this->bernd)->get(route('ausruestung.equipment'))->assertOk()
        ->assertSee('href="'.route('filament.ausruestung.pages.dashboard').'" title="Verwaltung"', false)->assertSee('Inventur')
        ->assertDontSee('>Admin</a>', false);
});

test('no Modulleiste outside modules and for modules the Kamerad has no access to', function () {
    $this->actingAs($this->hanna)->get(route('home'))->assertOk()->assertDontSee('data-module-bar', false);
    $this->actingAs($this->hanna)->get(route('personalverwaltung.index'))->assertForbidden();
});

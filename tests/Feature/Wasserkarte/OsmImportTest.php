<?php

use App\Models\Module;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Wasserkarte\src\Livewire\MapView;
use Modules\Wasserkarte\src\Models\Source;
use Modules\Wasserkarte\src\Services\OsmImporter;
use Modules\Wasserkarte\src\Services\WaterService;

beforeEach(function () {
    Module::where('key', 'wasserkarte')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $this->wart = User::factory()->create();
    $this->wart->moduleAccess()->create(['module' => 'wasserkarte', 'role' => 'hydrantenwart']);
    $this->hanna = User::factory()->create();
    $this->hanna->moduleAccess()->create(['module' => 'wasserkarte']);

    // From the Wasserversorger's list, 5 m next to OSM node 1002
    $this->csvHydrant = app(WaterService::class)->saveSource($this->wart, null, ['number' => 'H-7', 'kind' => 'unterflur', 'latitude' => 52.50045, 'longitude' => 9.7001]);

    Http::fake(['overpass-api.de/*' => Http::response(['elements' => [
        ['type' => 'node', 'id' => 1001, 'lat' => 52.501, 'lon' => 9.702, 'tags' => ['emergency' => 'fire_hydrant', 'fire_hydrant:type' => 'pillar', 'fire_hydrant:diameter' => '100', 'ref' => '12', 'flow_rate' => '1600']],
        ['type' => 'node', 'id' => 1002, 'lat' => 52.5005, 'lon' => 9.7001, 'tags' => ['emergency' => 'fire_hydrant', 'fire_hydrant:type' => 'underground', 'fire_hydrant:diameter' => '80']],
        ['type' => 'node', 'id' => 1003, 'lat' => 52.503, 'lon' => 9.705, 'tags' => ['emergency' => 'water_tank', 'water_tank:volume' => '100 m3', 'operator' => 'Gemeinde Musterstadt']],
        ['type' => 'node', 'id' => 1004, 'lat' => 52.504, 'lon' => 9.706, 'tags' => ['emergency' => 'suction_point', 'water_source' => 'pond']],
        ['type' => 'way', 'id' => 5, 'tags' => ['emergency' => 'fire_water_pond']],
    ]])]);
});

test('the preview shows what is new, what is linked to a source nearby and what is known; the import takes it over', function () {
    $map = Livewire::actingAs($this->wart)->test(MapView::class)
        ->call('osmPreview', 52.49, 9.69, 52.51, 9.71)
        ->assertSet('osm.total', 4)->assertSet('osm.new', 3)->assertSet('osm.link', 1)->assertSet('osm.known', 0)
        ->assertSee('Überflurhydrant 12 · DN 100')
        ->call('osmImport')->assertSee('3 neu, 1 mit vorhandenen verknüpft')->assertDispatched('water-markers');

    expect(Source::where('osm_id', 1001)->sole())->kind->toBe('ueberflur')->number->toBe('12')->pipe_dn->toBe(100)->flow_lpm->toBe(1600)->inspection_interval_months->toBe(12)
        ->and(Source::where('osm_id', 1003)->sole())->kind->toBe('behaelter')->capacity_m3->toBe(100)->operator->toBe('Gemeinde Musterstadt')
        ->and(Source::where('osm_id', 1004)->sole()->kind)->toBe('teich')
        // The list's hydrant keeps its number and position, gets the OSM id and the missing DN
        ->and($this->csvHydrant->fresh())->osm_id->toBe(1002)->number->toBe('H-7')->latitude->toBe(52.50045)->pipe_dn->toBe(80);

    Http::assertSent(fn (Request $r) => str_contains($r['data'], 'node["emergency"="fire_hydrant"](52.49,9.69,52.51,9.71)') && $r->hasHeader('User-Agent'));

    // A second import doubles nothing and keeps what the Hydrantenwart changed
    Source::where('osm_id', 1001)->update(['flow_lpm' => 1200]);
    $map->call('osmPreview', 52.49, 9.69, 52.51, 9.71)->assertSet('osm.new', 0)->assertSet('osm.known', 4)->call('osmImport');
    expect(Source::count())->toBe(4)->and(Source::where('osm_id', 1001)->value('flow_lpm'))->toBe(1200);
    $this->actingAs($this->wart)->get(route('wasserkarte.source', Source::where('osm_id', 1001)->sole()))->assertSee('Node 1001')->assertSee('ODbL');
});

test('too large sections, a busy Overpass and Kameraden without the role are refused', function () {
    expect(fn () => app(OsmImporter::class)->fetch(52.0, 9.0, 52.5, 9.2))->toThrow(ValidationException::class, 'zu groß');

    Http::swap(new Factory);
    Http::fake(['overpass-api.de/*' => Http::response('Too Many Requests', 429)]);
    Livewire::actingAs($this->wart)->test(MapView::class)->call('osmPreview', 52.49, 9.69, 52.51, 9.71)->assertHasErrors('osm')->assertSee('ausgelastet');

    Livewire::actingAs($this->hanna)->test(MapView::class)->assertDontSee('Aus OpenStreetMap übernehmen')->call('osmPreview', 52.49, 9.69, 52.51, 9.71)->assertForbidden();
    expect(fn () => app(OsmImporter::class)->import($this->hanna, []))->toThrow(ValidationException::class);
});

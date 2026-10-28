<?php

use App\Models\MemberFunction;
use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Modules\Personalverwaltung\src\Livewire\Honors;
use Modules\Personalverwaltung\src\Livewire\MemberList;
use Modules\Personalverwaltung\src\Livewire\Reports;
use Modules\Personalverwaltung\src\Models\HistoryEntry;
use Modules\Personalverwaltung\src\Models\Honor;
use Modules\Personalverwaltung\src\Models\HonorType;
use Modules\Personalverwaltung\src\Services\PersonnelService;

beforeEach(function () {
    config(['locations.enabled' => false]);
    Module::where('key', 'personalverwaltung')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $this->travelTo(Carbon::parse('2027-06-01 12:00', 'Europe/Berlin'));
    $this->pv = User::factory()->create();
    $this->pv->moduleAccess()->create(['module' => 'personalverwaltung', 'role' => 'personalverwalter']);
    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant', 'gender' => 'female', 'rank' => 'Feuerwehrfrau', 'joined_at' => '2015-01-01']);
    $this->bernd = User::factory()->create(['name' => 'Bernd Blaulicht', 'gender' => 'male', 'rank' => 'Feuerwehrmann', 'joined_at' => '2014-01-01']);
    $this->otto = User::factory()->create(['name' => 'Otto Ortsbrand', 'gender' => 'male', 'rank' => 'Löschmeister', 'joined_at' => '2000-01-01']);
    foreach ([$this->hanna, $this->bernd, $this->otto] as $u) {
        app(PersonnelService::class)->syncHistory($u);
    }
});

test('Sammelverarbeitung: promote several, each in their own form, with the date in the history', function () {
    $ids = [(string) $this->hanna->id, (string) $this->bernd->id];
    Livewire::actingAs($this->pv)->test(MemberList::class)
        ->set('selected', $ids)->set('bulkAction', 'promote')->set('bulkDate', '2027-05-15')->call('applyBulk')
        ->assertSee('2 erledigt')->assertSee('Hanna Hydrant → Oberfeuerwehrfrau')->assertSee('Bernd Blaulicht → Oberfeuerwehrmann');

    expect($this->hanna->fresh()->rank)->toBe('Oberfeuerwehrfrau')->and($this->hanna->fresh()->rank_since->toDateString())->toBe('2027-05-15');
    $current = HistoryEntry::where('user_id', $this->hanna->id)->where('kind', 'rank')->whereNull('until')->sole();
    expect($current->label)->toBe('Oberfeuerwehrfrau')->and($current->since->toDateString())->toBe('2027-05-15')
        ->and(HistoryEntry::where('user_id', $this->hanna->id)->where('kind', 'rank')->where('label', 'Feuerwehrfrau')->sole()->until->toDateString())->toBe('2027-05-15');

    // To a chosen rank: Otto is already above, skipped
    Livewire::actingAs($this->pv)->test(MemberList::class)
        ->set('selected', [(string) $this->bernd->id, (string) $this->otto->id])->set('bulkStep', '5')->call('applyBulk')
        ->assertSee('1 erledigt')->assertSee('übersprungen')->assertSee('Otto Ortsbrand');
    expect($this->bernd->fresh()->rank)->toBe('Löschmeister');
});

test('Sammelverarbeitung: Ehrung and Funktion for several at once', function () {
    $type = HonorType::create(['name' => 'Leistungsabzeichen Bronze']);
    Honor::create(['user_id' => $this->otto->id, 'honor_type_id' => $type->id, 'title' => $type->name, 'awarded_on' => '2020-05-01']);
    $all = [(string) $this->hanna->id, (string) $this->bernd->id, (string) $this->otto->id];

    Livewire::actingAs($this->pv)->test(MemberList::class)
        ->set('selected', $all)->set('bulkAction', 'honor')->set('bulkHonorType', (string) $type->id)->call('applyBulk')
        ->assertSee('2 erledigt')->assertSee('übersprungen');
    expect(Honor::where('honor_type_id', $type->id)->count())->toBe(3);

    $fn = MemberFunction::create(['name' => 'Gerätewart']);
    Livewire::actingAs($this->pv)->test(MemberList::class)
        ->set('selected', [(string) $this->hanna->id, (string) $this->bernd->id])->set('bulkAction', 'function_add')->set('bulkFunction', (string) $fn->id)->call('applyBulk')
        ->assertSee('Gerätewart');
    expect($this->hanna->fresh()->functions->pluck('name')->all())->toBe(['Gerätewart'])
        ->and(HistoryEntry::where('user_id', $this->hanna->id)->where('kind', 'function')->whereNull('until')->sole()->label)->toBe('Gerätewart');

    Livewire::actingAs($this->pv)->test(MemberList::class)->set('selected', [(string) $this->bernd->id])->set('bulkAction', 'function_remove')->set('bulkFunction', (string) $fn->id)->call('applyBulk');
    expect($this->bernd->fresh()->functions)->toHaveCount(0);

    // Without selection or Funktion: a clear message
    Livewire::actingAs($this->pv)->test(MemberList::class)->call('applyBulk')->assertHasErrors('bulk');
});

test('Auswertungen for Kommune and Verband, printable and as CSV; Ehrungsarten from presets and copies', function () {
    app(PersonnelService::class)->promote($this->pv, [$this->bernd->id], null, '2027-03-01');
    $fn = MemberFunction::create(['name' => 'Jugendwart']);
    $this->hanna->functions()->attach($fn);
    app(PersonnelService::class)->syncHistory($this->hanna->load('functions'));

    Livewire::actingAs($this->pv)->test(Reports::class)->assertSee('Funktionsträger')->assertSee('Jugendwart')->assertSee('Hanna Hydrant');
    Livewire::withQueryParams(['art' => 'dienstgrade'])->actingAs($this->pv)->test(Reports::class)
        ->assertSeeInOrder(['Löschmeister', 'Otto Ortsbrand', 'Oberfeuerwehrmann', 'Bernd Blaulicht', 'Feuerwehrfrau', 'Hanna Hydrant']);
    Livewire::withQueryParams(['art' => 'befoerderungen'])->actingAs($this->pv)->test(Reports::class)
        ->assertSee('Oberfeuerwehrmann')->assertSee('01.03.2027')->assertDontSee('Otto Ortsbrand');
    $csv = $this->actingAs($this->pv)->get(route('personalverwaltung.reports.csv', ['art' => 'befoerderungen', 'von' => '2027-01-01', 'bis' => '2027-12-31']))->assertOk()->getContent();
    expect($csv)->toContain('Beförderungen;Name;Angabe;Datum')->toContain('"Bernd Blaulicht";Oberfeuerwehrmann;01.03.2027');

    $honors = Livewire::actingAs($this->pv)->test(Honors::class)->call('addPresets')->assertSee('übliche Ehrungsarten angelegt');
    expect(HonorType::where('name', 'Feuerwehr-Ehrenzeichen 25 Jahre')->value('after_years'))->toBe(25);
    $honors->call('addPresets')->assertSee('gibt es schon')
        ->call('copyType', HonorType::where('name', 'Feuerwehr-Ehrenzeichen 25 Jahre')->value('id'))->assertSet('type.name', 'Feuerwehr-Ehrenzeichen 25 Jahre (Kopie)');

    $this->actingAs($this->hanna)->get(route('personalverwaltung.reports'))->assertForbidden();
});

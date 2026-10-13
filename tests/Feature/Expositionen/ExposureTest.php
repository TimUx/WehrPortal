<?php

use App\Livewire\Dashboard\Home;
use App\Models\Module;
use App\Models\User;
use App\Services\MemberRemoval;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\Einsatzbericht\src\Services\ReportService;
use Modules\Expositionen\src\Livewire\CrewForm;
use Modules\Expositionen\src\Livewire\ExposureForm;
use Modules\Expositionen\src\Livewire\Manage;
use Modules\Expositionen\src\Livewire\MyExposures;
use Modules\Expositionen\src\Models\Exposure;
use Modules\Expositionen\src\Notifications\ExposureProposedNotification;
use Modules\Expositionen\src\Notifications\ExposureRecordedNotification;
use Modules\Expositionen\src\Services\ExposureService;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

beforeEach(function () {
    Module::whereIn('key', ['expositionen', 'einsatzbericht'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Notification::fake();
    $this->travelTo(Carbon::parse('2027-06-01 12:00', 'Europe/Berlin'));

    $this->officer = User::factory()->create(['name' => 'Erika Exposition']);
    $this->officer->moduleAccess()->create(['module' => 'expositionen', 'role' => 'expositionsbeauftragter']);
    $this->leader = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $this->leader->moduleAccess()->create(['module' => 'einsatzbericht', 'role' => 'einsatzleiter']);
    $this->leader->moduleAccess()->create(['module' => 'expositionen']);
    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant']);
    $this->fritz = User::factory()->create(['name' => 'Fritz Feuer']);
    foreach ([$this->hanna, $this->fritz] as $u) {
        $u->moduleAccess()->create(['module' => 'expositionen']);
    }

    // A Kellerbrand two days ago: Hanna under Atemschutz, Fritz as Maschinist
    $reports = app(ReportService::class);
    $this->report = $reports->create($this->leader, now()->subDays(2));
    $reports->update($this->leader, $this->report, ['keyword' => 'FEU 2', 'category' => 'brand_mittel']);
    $reports->start($this->leader, $this->report->refresh());
    $hlf = $reports->addVehicle($this->leader, $this->report, Vehicle::factory()->create(['is_active' => true])->id);
    $reports->assign($this->leader, $hlf, $this->hanna, 'angriffstrupp', true);
    $reports->assign($this->leader, $hlf, $this->fritz, 'maschinist');
    $this->report->refresh();
});

$data = fn (array $extra = []) => ['occurred_on' => '2027-05-30', 'occasion' => 'Einsatz 2027/001 · FEU 2', 'activity' => 'innenangriff', 'activity_text' => 'Kellerbrand',
    'substances' => ['Brandrauch / Brandgase (PAK, Ruß)'], 'other_substances' => 'Styrol (CAS 100-42-5)', 'duration_minutes' => '35', 'routes' => ['inhalativ', 'dermal'],
    'protection' => ['schutzkleidung'], 'notes' => '', ...$extra];

test('the Einsatzleiter proposes for the crew, each confirms; the Beauftragte hears about it', function () use ($data) {
    Livewire::actingAs($this->leader)->test(CrewForm::class, ['report' => $this->report])
        ->assertSet('crewIds', [(string) $this->hanna->id])   // preselected: under Atemschutz
        ->assertSet('form.occasion', 'Einsatz '.$this->report->number.' · FEU 2')
        ->set('crewIds', [(string) $this->hanna->id, (string) $this->fritz->id])->set('form', $data())->call('save')->assertRedirect();

    $hanna = Exposure::where('user_id', $this->hanna->id)->sole();
    expect($hanna)->status->toBe('draft')->report_id->toBe($this->report->id)
        ->and($hanna->protection)->toBe(['schutzkleidung', 'atemschutz'])
        ->and($hanna->substances)->toBe(['Brandrauch / Brandgase (PAK, Ruß)', 'Styrol (CAS 100-42-5)'])
        ->and($hanna->retain_until->toDateString())->toBe('2067-05-30')
        ->and(Exposure::where('user_id', $this->fritz->id)->sole()->protection)->toBe(['schutzkleidung']);
    Notification::assertSentTo($this->hanna, ExposureProposedNotification::class, fn ($n, $c, $u) => ! str_contains(implode(' ', $n->toMail($u)->introLines), 'Brandrauch'));

    // Encrypted in the database
    expect(DB::table('ex_exposures')->where('id', $hanna->id)->value('substances'))->not->toContain('Brandrauch');

    // Hanna confirms with her own duration; Fritz was not at the fire and discards
    Livewire::actingAs($this->hanna)->test(ExposureForm::class, ['exposure' => $hanna])->assertSee('Bestätigen')
        ->set('form.duration_minutes', '50')->call('save')->assertRedirect(route('expositionen.index'));
    expect($hanna->fresh())->status->toBe('recorded')->duration_minutes->toBe(50);
    Notification::assertSentTo($this->officer, ExposureRecordedNotification::class);
    Livewire::actingAs($this->fritz)->test(ExposureForm::class, ['exposure' => Exposure::where('user_id', $this->fritz->id)->sole()])->call('discard')->assertRedirect();
    expect(Exposure::where('user_id', $this->fritz->id)->exists())->toBeFalse();

    // A Kamerad can't propose
    $this->actingAs($this->fritz)->get(route('expositionen.crew', $this->report))->assertForbidden();
});

test('after a Brand-Einsatz the start page asks; "Nein" stops it, recording answers it', function () use ($data) {
    Livewire::actingAs($this->fritz)->test(Home::class)->assertSee('Warst du beim letzten Brandeinsatz Gefahrstoffen ausgesetzt?');
    Livewire::actingAs($this->fritz)->test(MyExposures::class)->assertSee('Einsatz '.$this->report->number)->call('dismiss', $this->report->id)->assertDontSee('Brandrauch oder anderen');
    Livewire::actingAs($this->fritz)->test(Home::class)->assertDontSee('Warst du beim letzten Brandeinsatz');

    // Hanna records it herself from the prompt: occasion and Atemschutz come along
    $form = Livewire::withQueryParams(['einsatz' => $this->report->id])->actingAs($this->hanna)->test(ExposureForm::class)
        ->assertSet('form.protection', ['atemschutz'])->assertSet('form.occasion', 'Einsatz '.$this->report->number.' · FEU 2');
    $form->set('form.activity', 'innenangriff')->set('form.substances', ['Brandrauch / Brandgase (PAK, Ruß)'])->call('save')->assertHasNoErrors();
    expect(Exposure::where('user_id', $this->hanna->id)->sole())->status->toBe('recorded')->report_id->toBe($this->report->id);
    expect(app(ExposureService::class)->openPrompts($this->hanna))->toHaveCount(0);

    // Without Gefahrstoff or with a date in the future: no
    Livewire::actingAs($this->hanna)->test(ExposureForm::class)->set('form', $data(['substances' => [], 'other_substances' => '', 'occurred_on' => '2027-06-02']))
        ->call('save')->assertHasErrors(['substances', 'occurred_on']);
});

test('the Meldeliste: CSV for the ZED, marking reported, the Nachweis; kept 40 years', function () use ($data) {
    $service = app(ExposureService::class);
    $e = $service->record($this->hanna, $this->hanna, $data(), $this->report);

    $list = Livewire::actingAs($this->officer)->test(Manage::class)->assertSee('Hanna Hydrant')->assertSee('Styrol');
    $csv = $this->actingAs($this->officer)->get(route('expositionen.csv', ['status' => 'recorded']))->assertOk()->getContent();
    expect($csv)->toContain('Name;Geburtsdatum;Datum;Anlass;Tätigkeit')->toContain('Hanna Hydrant')->toContain('Brandbekämpfung im Innenangriff – Kellerbrand');

    $list->call('markReported')->assertHasErrors('selected')
        ->set('selected', [(string) $e->id])->set('reference', 'ZED-4711')->call('markReported')->assertSee('1 als an die ZED gemeldet vermerkt');
    expect($e->fresh())->status->toBe('reported')->zed_reference->toBe('ZED-4711');

    // Reported: Hanna can no longer change it, the Beauftragte can
    Livewire::actingAs($this->hanna)->test(ExposureForm::class, ['exposure' => $e])->call('save')->assertHasErrors('exposure');

    $this->actingAs($this->hanna)->get(route('expositionen.certificate', $this->hanna))->assertOk()->assertSee('Nachweis über Gefahrstoff-Expositionen')->assertSee('gemeldet 01.06.2027');
    $this->actingAs($this->fritz)->get(route('expositionen.certificate', $this->hanna))->assertForbidden();
    $this->actingAs($this->fritz)->get(route('expositionen.manage'))->assertForbidden();

    // Not deletable before retain_until – not even by accident
    expect(fn () => DB::transaction(fn () => DB::table('ex_exposures')->where('id', $e->id)->delete()))->toThrow(QueryException::class);
    $this->artisan('model:prune', ['--model' => [Exposure::class]])->assertSuccessful();
    expect(Exposure::count())->toBe(1);
    // 40 years later (the database checks its own date, so the period is moved instead of the clock)
    Exposure::query()->update(['retain_until' => '2020-01-01']);
    $this->travelBack();
    $this->artisan('model:prune', ['--model' => [Exposure::class]])->assertSuccessful();
    expect(Exposure::count())->toBe(0);
});

test('deleting a Kamerad keeps the exposure with the name, never moves it to someone else', function () use ($data) {
    app(ExposureService::class)->record($this->fritz, $this->fritz, $data());
    $admin = User::factory()->create(['is_admin' => true]);
    app(MemberRemoval::class)->transferAndDelete($this->fritz, $this->hanna, $admin);

    $e = Exposure::sole();
    expect($e->user_id)->toBeNull()->and($e->person_name)->toBe('Fritz Feuer')->and($e->recorded_by)->toBeNull();
});

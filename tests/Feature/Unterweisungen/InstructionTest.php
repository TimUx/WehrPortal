<?php

use App\Livewire\Dashboard\Home;
use App\Models\Group;
use App\Models\Module;
use App\Models\User;
use App\Services\BadgeRegistry;
use App\Services\DriverRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\Atemschutz\src\Models\Training;
use Modules\Atemschutz\src\Models\Wearer;
use Modules\Atemschutz\src\Services\ReadinessService;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Kalender\src\Services\EventService;
use Modules\Unterweisungen\src\Livewire\MyInstructions;
use Modules\Unterweisungen\src\Livewire\Overview;
use Modules\Unterweisungen\src\Livewire\SessionDetail;
use Modules\Unterweisungen\src\Livewire\SessionForm;
use Modules\Unterweisungen\src\Livewire\Topics;
use Modules\Unterweisungen\src\Models\Attendance;
use Modules\Unterweisungen\src\Models\Session;
use Modules\Unterweisungen\src\Models\Topic;
use Modules\Unterweisungen\src\Notifications\InstructionDueNotification;
use Modules\Unterweisungen\src\Services\InstructionService;

beforeEach(function () {
    Module::whereIn('key', ['unterweisungen', 'kalender', 'atemschutz', 'fahrtenbuch'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Notification::fake();
    $this->ea = Group::create(['name' => 'Einsatzabteilung', 'sort_order' => 1]);
    $this->boss = User::factory()->create(['name' => 'Ulla Unterweiserin']);
    $this->boss->moduleAccess()->create(['module' => 'unterweisungen', 'role' => 'unterweisungsbeauftragter']);
    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant']);
    $this->bernd = User::factory()->create(['name' => 'Bernd Blaulicht']);
    foreach ([$this->hanna, $this->bernd] as $u) {
        $u->moduleAccess()->create(['module' => 'unterweisungen']);
        $u->groups()->attach($this->ea);
    }
    $this->s = app(InstructionService::class);
});

test('an Unterweisung is held, the participants sign on the tablet, the Nachweis is printed', function () {
    Livewire::actingAs($this->boss)->test(Topics::class)->call('presets')->assertSee('übliche Unterweisungen angelegt');
    $uvv = Topic::where('short', 'UVV')->sole();
    expect($uvv->requiredFor($this->hanna))->toBeTrue();

    Livewire::actingAs($this->boss)->withQueryParams(['art' => $uvv->id])->test(SessionForm::class)
        ->assertSee('Hanna Hydrant')->set('userIds', [(string) $this->hanna->id, (string) $this->bernd->id])->call('save')->assertRedirect();
    $session = Session::sole();
    expect($session->attendances)->toHaveCount(2);

    // Tablet passed around: one after the other
    $first = Attendance::where('user_id', $this->bernd->id)->sole();
    Livewire::actingAs($this->boss)->test(SessionDetail::class, ['session' => $session])
        ->set('signing', $first->id)->set('signature', signaturePng())->call('sign')->assertHasNoErrors()
        ->assertSet('signing', Attendance::where('user_id', $this->hanna->id)->value('id'));
    expect($first->fresh()->signed_at)->not->toBeNull();

    // Hanna signs later on her own phone
    $own = Livewire::actingAs($this->hanna)->test(MyInstructions::class)->assertSee('Bitte unterschreiben')
        ->set('signing', Attendance::where('user_id', $this->hanna->id)->value('id'))->set('signature', signaturePng())->call('sign');
    $own->assertDontSee('Bitte unterschreiben')->assertSee('UVV');
    $this->actingAs($this->boss)->get(route('unterweisungen.print', $session))->assertOk()->assertSee('Nachweis über die Unterweisung')->assertSee('data:image/png;base64', false);
    $this->actingAs($this->hanna)->get(route('unterweisungen.overview'))->assertForbidden();
});

test('state per Kamerad, reminders, overview, Kürzel and Atemschutz', function () {
    $uvv = Topic::create(['name' => 'Jährliche Unterweisung', 'short' => 'UVV', 'interval_months' => 12, 'for_all' => true]);
    $as = Topic::create(['name' => 'Atemschutz-Unterweisung', 'short' => 'AS-U', 'interval_months' => 12, 'counts_as' => 'atemschutz', 'group_ids' => [$this->ea->id]]);
    $this->s->recordSession($this->boss, ['topic_id' => $uvv->id, 'held_on' => now()->subMonths(11)->subDays(20)->toDateString(), 'instructor_name' => 'Ulla'], [$this->hanna->id]);
    $this->s->recordSession($this->boss, ['topic_id' => $as->id, 'held_on' => now()->subMonth()->toDateString(), 'instructor_name' => 'Ulla'], [$this->hanna->id]);

    $rows = $this->s->forUser($this->hanna)->keyBy(fn ($r) => $r['topic']->short);
    expect($rows['UVV']['state'])->toBe('soon')->and($rows['AS-U']['state'])->toBe('ok')
        ->and($this->s->forUser($this->bernd)->firstWhere('topic.short', 'UVV')['state'])->toBe('missing');

    Livewire::actingAs($this->boss)->test(Overview::class)->assertSee('Hanna Hydrant')->assertSee('fehlt');
    Livewire::actingAs($this->bernd)->test(Home::class)->assertSee('Jährliche Unterweisung: fehlt');

    $this->artisan('unterweisungen:remind')->assertSuccessful();
    Notification::assertSentTo($this->hanna, InstructionDueNotification::class);
    Notification::assertSentTo($this->boss, InstructionDueNotification::class);
    Notification::fake();
    $this->artisan('unterweisungen:remind')->assertSuccessful();
    Notification::assertNotSentTo($this->hanna, InstructionDueNotification::class);

    expect(app(BadgeRegistry::class)->for([$this->hanna->id])[$this->hanna->id])->toContain('UVV')->toContain('AS-U');

    // Counts as the Atemschutz-Unterweisung (FwDV 7)
    $wearer = Wearer::create(['user_id' => $this->hanna->id, 'is_agt' => true, 'g263_valid_until' => now()->addYear()->toDateString()]);
    Training::create(['user_id' => $this->hanna->id, 'kind' => 'belastungsuebung', 'done_on' => now()->subMonth()->toDateString()]);
    expect(app(ReadinessService::class)->forWearer($wearer)['ready'])->toBeTrue();
});

test('Einweisung on a vehicle: the Fahrtenbuch hints; participants from a Kalender event', function () {
    $mtw = Vehicle::factory()->create(['name' => 'MTW', 'is_active' => true]);
    Topic::create(['name' => 'Einweisung MTW', 'short' => 'MTW', 'kind' => 'einweisung', 'vehicle_id' => $mtw->id]);
    expect(app(DriverRegistry::class)->warning($this->hanna, $mtw->id))->toContain('nicht (mehr) eingewiesen: Einweisung MTW');

    $this->travelTo(Carbon::parse('2027-06-01 12:00', 'Europe/Berlin'));
    $chief = User::factory()->create();
    $chief->moduleAccess()->create(['module' => 'kalender', 'role' => 'terminverwalter']);
    $event = app(EventService::class)->create(['title' => 'Fahrzeugeinweisung', 'type' => 'uebung', 'starts_at' => now()->subHours(3), 'ends_at' => now()->subHour()], [], $chief)->first();
    app(EventService::class)->setAttendance($event, $this->hanna, true, $chief);
    expect($this->s->fromEvent($event->id))->toBe([$this->hanna->id]);

    $this->s->recordSession($this->boss, ['topic_id' => Topic::sole()->id, 'held_on' => '2027-06-01', 'instructor_name' => 'Ulla'], $this->s->fromEvent($event->id));
    expect(app(DriverRegistry::class)->warning($this->hanna, $mtw->id))->toBeNull();
});

<?php

use App\Livewire\Dashboard\Home;
use App\Models\Group;
use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Modules\Mitteilungen\src\Livewire\Manage;
use Modules\Mitteilungen\src\Models\Announcement;
use Modules\Mitteilungen\src\Notifications\AnnouncementNotification;

beforeEach(function () {
    Module::where('key', 'mitteilungen')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');

    $this->member = User::factory()->create(['name' => 'Hanna Hydrant', 'phone' => null]);
    $this->member->moduleAccess()->create(['module' => 'mitteilungen']);
    $this->redakteur = User::factory()->create(['name' => 'Bernd Blaulicht', 'phone' => null]);
    $this->redakteur->moduleAccess()->create(['module' => 'mitteilungen', 'role' => 'redakteur']);
});

test('the module is on after the update and new Kameraden get it automatically', function () {
    expect(Module::find('mitteilungen'))->is_active->toBeTrue()->auto_assign->toBeTrue();
});

test('the migration gives everyone access and keeps the right to post as Redakteur', function () {
    $migration = require base_path('modules/Mitteilungen/database/migrations/2026_10_20_000000_make_mitteilungen_a_module.php');
    $migration->down();

    $poster = User::factory()->create();
    $reader = User::factory()->create();
    DB::table('users')->where('id', $poster->id)->update(['can_post_announcements' => true]);

    $migration->up();

    expect(Schema::hasColumn('users', 'can_post_announcements'))->toBeFalse()
        ->and($poster->refresh()->hasModuleRole('mitteilungen', 'redakteur'))->toBeTrue()
        ->and($reader->refresh()->moduleAccess()->where('module', 'mitteilungen')->exists())->toBeTrue()
        ->and($reader->hasModuleRole('mitteilungen', 'redakteur'))->toBeFalse();
});

test('only admins and Redakteure manage notices', function () {
    $this->actingAs($this->member)->get('/mitteilungen')->assertOk();
    $this->actingAs($this->member)->get('/mitteilungen/verwalten')->assertForbidden();
    $this->actingAs($this->redakteur)->get('/mitteilungen/verwalten')->assertOk();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->get('/mitteilungen/verwalten')->assertOk();
});

test('the start page shows notices only when there are some', function () {
    // The module tile itself is called "Mitteilungen", so look for the board's own link
    Livewire::actingAs($this->member)->test(Home::class)->assertDontSee('Alle Mitteilungen');

    Announcement::create(['title' => 'Grillabend Samstag']);

    Livewire::actingAs($this->member)->test(Home::class)->assertSee('Alle Mitteilungen')->assertSee('Grillabend Samstag');
});

test('a notice is written, shown to its audience and can be mailed', function () {
    Notification::fake();
    $youth = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 1]);
    $this->member->groups()->attach($youth);
    $outsider = User::factory()->create();
    $outsider->moduleAccess()->create(['module' => 'mitteilungen']);

    Livewire::actingAs($this->redakteur)->test(Manage::class)
        ->set('title', 'Zeltlager-Anmeldung bis Freitag')
        ->set('body', 'Bitte bei Bernd abgeben.')
        ->set('groupIds', [(string) $youth->id])
        ->set('mail', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(Announcement::sole()->groups->pluck('name')->all())->toBe(['Jugendfeuerwehr']);
    Livewire::actingAs($this->member)->test(Home::class)->assertSee('Zeltlager-Anmeldung bis Freitag');
    Livewire::actingAs($outsider)->test(Home::class)->assertDontSee('Zeltlager-Anmeldung bis Freitag');

    Notification::assertSentTo($this->member, AnnouncementNotification::class);
    Notification::assertNotSentTo($outsider, AnnouncementNotification::class);
});

test('notices respect their visibility period', function () {
    Announcement::create(['title' => 'Kommt noch', 'visible_from' => now()->addDay()]);
    Announcement::create(['title' => 'Schon vorbei', 'visible_until' => now()->subMinute()]);
    Announcement::create(['title' => 'Gilt jetzt', 'visible_from' => now()->subDay(), 'visible_until' => now()->addDay()]);

    Livewire::actingAs($this->member)->test(Home::class)
        ->assertSee('Gilt jetzt')->assertDontSee('Kommt noch')->assertDontSee('Schon vorbei');
});

test('normal notices can be dismissed on the start page, important ones stay and come first', function () {
    $normal = Announcement::create(['title' => 'Grillabend Samstag']);
    $important = Announcement::create(['title' => 'Atemschutz-Untersuchung Pflicht', 'important' => true]);

    $html = Livewire::actingAs($this->member)->test(Home::class)->html();
    expect(strpos($html, 'Atemschutz-Untersuchung Pflicht'))->toBeLessThan(strpos($html, 'Grillabend Samstag'));

    $this->actingAs($this->member)->from('/')->post(route('mitteilungen.dismiss', $normal))->assertRedirect('/');
    $this->actingAs($this->member)->post(route('mitteilungen.dismiss', $important));

    Livewire::actingAs($this->member)->test(Home::class)->assertDontSee('Grillabend Samstag')->assertSee('Atemschutz-Untersuchung Pflicht');

    // The module page still lists the dismissed one
    $this->actingAs($this->member)->get('/mitteilungen')->assertSee('Grillabend Samstag')->assertSee('auf der Startseite ausgeblendet');
});

test('a period is entered as TT.MM.JJJJ and must be in order', function () {
    Livewire::actingAs($this->redakteur)->test(Manage::class)
        ->set('title', 'Test')->set('visibleFrom', '2027-01-01')->call('save')->assertHasErrors('visibleFrom')
        ->set('visibleFrom', '10.01.2027')->set('visibleUntil', '01.01.2027')->call('save')->assertHasErrors('visibleUntil')
        ->set('visibleUntil', '31.01.2027')->call('save')->assertHasNoErrors();

    $a = Announcement::sole();
    expect($a->visible_from->setTimezone('Europe/Berlin')->format('d.m.Y H:i'))->toBe('10.01.2027 00:00')
        ->and($a->visible_until->setTimezone('Europe/Berlin')->format('d.m.Y H:i'))->toBe('31.01.2027 23:59');
});

test('without access to the module no notices appear', function () {
    // With "automatisch freischalten" everyone has access; switched off, only granted Kameraden
    Module::where('key', 'mitteilungen')->update(['auto_assign' => false]);
    Cache::forget('module_auto_assign_mitteilungen');
    Announcement::create(['title' => 'Grillabend Samstag']);
    $noAccess = User::factory()->create();

    Livewire::actingAs($noAccess)->test(Home::class)->assertDontSee('Grillabend Samstag');
    $this->actingAs($noAccess)->get('/mitteilungen')->assertForbidden();
});

test('notices on the start page stand out in orange, important ones in dark red', function () {
    Announcement::create(['title' => 'Grillabend Samstag']);
    Announcement::create(['title' => 'Alarmübung', 'important' => true]);

    Livewire::actingAs($this->member)->test(Home::class)
        ->assertSeeHtml('bg-fw-orange')
        ->assertSeeHtml('bg-fw-red-dark')
        ->assertSee('Wichtige Mitteilung');
});

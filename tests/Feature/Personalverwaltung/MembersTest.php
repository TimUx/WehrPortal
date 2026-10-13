<?php

use App\Models\Group;
use App\Models\Module;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Services\Invitation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\Personalverwaltung\src\Livewire\MemberFile;
use Modules\Personalverwaltung\src\Livewire\MemberList;
use Modules\Personalverwaltung\src\Livewire\NewMembers;

beforeEach(function () {
    Module::where('key', 'personalverwaltung')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Notification::fake();
    $this->ea = Group::create(['name' => 'Einsatzabteilung', 'sort_order' => 1]);
    $this->jf = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 2]);
    $this->pv = User::factory()->create();
    $this->pv->moduleAccess()->create(['module' => 'personalverwaltung', 'role' => 'personalverwalter']);
});

test('the Personalverwalter takes in a new Kamerad and invites by QR code; the first login welcomes', function () {
    $nm = Livewire::actingAs($this->pv)->test(NewMembers::class)
        ->set('member', ['name' => 'Nina Neu', 'email' => 'Nina@example.com', 'group_ids' => [(string) $this->ea->id], 'joined_at' => '2026-09-01', 'rank' => '', 'gender' => 'female'])
        ->call('create')->assertHasNoErrors()->assertSee('Einladung für Nina Neu')->assertSee('QR-Code der Einladung');
    $nina = User::where('email', 'nina@example.com')->sole();
    expect($nina)->status->value->toBe('active')->and($nina->groups->pluck('name')->all())->toBe(['Einsatzabteilung'])
        ->and($nina->canUseModule('personalverwaltung'))->toBeTrue();

    $nm->call('mailInvite');
    Notification::assertSentTo($nina, InvitationNotification::class);

    // The link: GET shows, POST logs in – the very first login leads to Willkommen
    $url = app(Invitation::class)->create($nina)['url'];
    auth()->logout();
    $this->get($url)->assertOk();
    $this->post($url)->assertRedirect(route('welcome'));
    $this->get(route('welcome'))->assertOk()->assertSee('Willkommen, Nina Neu!')->assertSee('Profilfoto hochladen');
    // Once only
    auth()->logout();
    $this->post($url)->assertRedirect(route('auth.magic-link.form'));

    // Same address twice: no
    Livewire::actingAs($this->pv)->test(NewMembers::class)
        ->set('member', ['name' => 'Nina Doppelt', 'email' => 'nina@example.com', 'group_ids' => [], 'joined_at' => '', 'rank' => '', 'gender' => ''])->call('create')->assertHasErrors('member_email');
});

test('members come in from a CSV with a preview: new ones created, known ones completed', function () {
    $known = User::factory()->create(['name' => 'Bernd Blaulicht', 'email' => 'bernd@example.com']);
    $csv = "Nachname;Vorname;E-Mail;Abteilung;Dienstgrad;Eintritt\n"
        ."Hydrant;Hanna;hanna@example.com;Einsatzabteilung, Jugendfeuerwehr;Feuerwehrfrau;01.03.2015\n"
        ."Blaulicht;Bernd;bernd@example.com;Einsatzabteilung;Oberfeuerwehrmann;\n"
        ."Kaputt;Karl;keine-mail;;;\n"
        ."Musik;Mia;mia@example.com;Musikzug;;\n";

    Livewire::actingAs($this->pv)->test(NewMembers::class)
        ->set('file', UploadedFile::fake()->createWithContent('liste.csv', $csv))
        ->assertSee('wird angelegt')->assertSee('wird ergänzt')->assertSee('gültige E-Mail')->assertSee('„Musikzug“ gibt es nicht')->assertSee('2 übernehmen')
        ->call('import')->assertSee('1 angelegt, 1 ergänzt');
    $hanna = User::where('email', 'hanna@example.com')->sole();
    expect($hanna->name)->toBe('Hanna Hydrant')->and($hanna->joined_at->toDateString())->toBe('2015-03-01')
        ->and($hanna->groups->pluck('name')->sort()->values()->all())->toBe(['Einsatzabteilung', 'Jugendfeuerwehr'])
        ->and($known->fresh()->rank)->toBe('Oberfeuerwehrmann');
});

test('Personaleinsicht sees the files read-only, without personal data and without changing anything', function () {
    $hanna = User::factory()->create(['name' => 'Hanna Hydrant', 'phone' => '0170 123']);
    $hanna->groups()->attach($this->ea);
    $viewer = User::factory()->create();
    $viewer->moduleAccess()->create(['module' => 'personalverwaltung', 'role' => 'personaleinsicht']);

    $this->actingAs($viewer)->get(route('personalverwaltung.index'))->assertOk()->assertSee('Hanna Hydrant')->assertDontSee('Neue Mitglieder');
    Livewire::actingAs($viewer)->test(MemberFile::class, ['member' => $hanna])
        ->assertSee('Diensthistorie')->assertDontSee('Nachtragen')->assertDontSee('Persönliche Daten')->assertDontSee('Einladung (QR-Code)')
        ->set('stage', ['kind' => 'event', 'label' => 'Verpflichtung', 'since' => '2020-01-01', 'until' => '', 'note' => ''])->call('addStage')->assertHasErrors();
    Livewire::actingAs($viewer)->test(MemberList::class)->assertDontSee('Sammelverarbeitung')
        ->set('selected', [(string) $hanna->id])->call('applyBulk')->assertHasErrors('bulk');
    $this->actingAs($viewer)->get(route('personalverwaltung.reports'))->assertOk();
    foreach (['new', 'settings', 'honors', 'requests'] as $route) {
        $this->get(route('personalverwaltung.'.$route))->assertForbidden();
    }
});

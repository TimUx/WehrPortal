<?php

use App\Livewire\Dashboard\Home;
use App\Livewire\Profile\Credentials;
use App\Models\Group;
use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Modules\Atemschutz\src\Models\Training;
use Modules\Atemschutz\src\Models\Wearer;
use Modules\Personalverwaltung\src\Filament\Pages\SettingsPage;
use Modules\Personalverwaltung\src\Livewire\ChangeRequests;
use Modules\Personalverwaltung\src\Livewire\MemberFile;
use Modules\Personalverwaltung\src\Livewire\OwnFile;
use Modules\Personalverwaltung\src\Models\ChangeRequest;
use Modules\Personalverwaltung\src\Models\MemberProfile;
use Modules\Personalverwaltung\src\Notifications\ChangeRequestAnsweredNotification;
use Modules\Personalverwaltung\src\Notifications\ChangeRequestedNotification;

beforeEach(function () {
    config(['locations.enabled' => false]);
    Module::whereIn('key', ['personalverwaltung', 'atemschutz'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $this->ea = Group::create(['name' => 'Einsatzabteilung', 'sort_order' => 1]);
    $this->pv = User::factory()->create(['name' => 'Petra Personal']);
    $this->pv->moduleAccess()->create(['module' => 'personalverwaltung', 'role' => 'personalverwalter']);
    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant', 'rank' => 'Oberfeuerwehrfrau']);
    $this->hanna->groups()->attach($this->ea);
    foreach (['personalverwaltung', 'atemschutz'] as $m) {
        $this->hanna->moduleAccess()->create(['module' => $m]);
    }
});

test('a Kamerad reports an Änderungswunsch, the Personalverwalter answers it', function () {
    Notification::fake();
    Livewire::actingAs($this->hanna)->test(OwnFile::class)
        ->set('topic', 'laufbahn')->set('requestText', 'Beförderung war am 12.03.2027')->call('requestChange')
        ->assertHasNoErrors()->assertSee('Dein Änderungswunsch ist bei der Personalverwaltung')->assertSee('Offen')
        ->set('requestText', ' ')->call('requestChange')->assertHasErrors('request_message');
    $request = ChangeRequest::sole();
    Notification::assertSentTo($this->pv, ChangeRequestedNotification::class);

    Livewire::actingAs($this->pv)->test(Home::class)->assertSee('1 offener Änderungswunsch');
    $list = Livewire::actingAs($this->pv)->test(ChangeRequests::class)->assertSee('Beförderung war am 12.03.2027');
    // Not taken over needs a reason
    $list->call('answer', $request->id, 'declined')->assertHasErrors('request-'.$request->id);
    $list->set('answers.'.$request->id, 'Ist korrigiert.')->call('answer', $request->id, 'done')->assertSee('erledigt');

    expect($request->fresh())->status->toBe('done')->answer->toBe('Ist korrigiert.')->handled_by->toBe($this->pv->id);
    Notification::assertSentTo($this->hanna, ChangeRequestAnsweredNotification::class);
    Livewire::actingAs($this->hanna)->test(OwnFile::class)->assertSee('Erledigt')->assertSee('Antwort: Ist korrigiert.');

    // Kameraden don't get the list
    $this->actingAs($this->hanna)->get(route('personalverwaltung.requests'))->assertForbidden();
});

test('Dienstausweis and Atemschutzpass on the phone, checked by a QR code that runs out', function () {
    // Nothing yet: no Dienstausweis switched on, not in the pool
    $this->actingAs($this->hanna)->get(route('credentials'))->assertOk()->assertSee('keine digitalen Nachweise');
    $this->get(route('profile'))->assertDontSee(route('credentials'))->assertDontSee(route('personalverwaltung.digital-id'));
    $this->get(route('personalverwaltung.digital-id'))->assertNotFound();

    Livewire::actingAs($this->pv)->test(SettingsPage::class)->set('fields.digital_id', true)->call('save');
    MemberProfile::create(['user_id' => $this->hanna->id, 'id_card_number' => 'MS-0815', 'id_card_valid_until' => now()->addYear()->toDateString()]);
    Wearer::create(['user_id' => $this->hanna->id, 'is_agt' => true, 'g263_examined_on' => now()->subYear()->toDateString(), 'g263_valid_until' => now()->addYears(2)->toDateString()]);
    foreach (['belastungsuebung', 'unterweisung'] as $kind) {
        Training::create(['user_id' => $this->hanna->id, 'kind' => $kind, 'done_on' => now()->subMonth()->toDateString()]);
    }

    // The Dienstausweis has its own page behind the card icon in the header, not under Nachweise
    $this->actingAs($this->hanna)->get(route('profile'))->assertSee(route('personalverwaltung.digital-id'))->assertSee(route('credentials'));
    $this->get(route('personalverwaltung.digital-id'))->assertOk()
        ->assertSee('Dienstausweis')->assertSee('Hanna Hydrant')->assertSee('Oberfeuerwehrfrau')->assertSee('MS-0815')->assertSee('Ausweisnummer')->assertSee('QR-Code zur Prüfung');
    Livewire::actingAs($this->hanna)->test(Credentials::class)
        ->assertDontSee('Nr. MS-0815')
        ->assertSee('Atemschutzpass')->assertSee('Einsatzbereit')->assertSee('Tragezeit')->assertSee('QR-Code zur Prüfung');

    // Every active Kamerad sees it, also without access to the Personalverwaltung
    $bernd = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $this->actingAs($bernd)->get(route('personalverwaltung.digital-id'))->assertOk()->assertSee('Bernd Blaulicht');
    $this->actingAs($bernd)->get(route('profile'))->assertSee(route('personalverwaltung.digital-id'));
    $this->actingAs($this->hanna);

    // The checker's page: no login, signed, today's state
    auth()->logout();
    $url = URL::temporarySignedRoute('credentials.check', now()->addMinutes(10), ['user' => $this->hanna->id]);
    $this->get($url)->assertOk()->assertSee('Aktives Mitglied')->assertSee('MS-0815')->assertSee('Einsatzbereit unter Atemschutz');
    $this->get(route('credentials.check', $this->hanna))->assertForbidden();

    // Left the Wehr: the same code shows it at once
    $this->hanna->update(['status' => 'left']);
    $this->get($url)->assertOk()->assertSee('Kein aktives Mitglied')->assertDontSee('MS-0815');

    $this->travel(11)->minutes();
    $this->get($url)->assertForbidden();
});

test('the Dienstausweis like the official card: Geburtsdatum and -ort, Behörde, Feuerwehr; options of the Personalverwaltung', function () {
    Livewire::actingAs($this->pv)->test(SettingsPage::class)
        ->set('fields.digital_id', true)->set('fields.birth_date', true)->set('fields.id_issuer', 'Gemeinde Musterstadt')->call('save');
    $this->hanna->update(['birth_date' => '1990-04-12']);
    Livewire::actingAs($this->pv)->test(MemberFile::class, ['member' => $this->hanna])
        ->set('idCard.number', 'MS-4711')->set('idCard.valid_until', now()->addYears(2)->toDateString())->set('idCard.birth_place', 'Musterhausen')
        ->call('saveIdCard')->assertHasNoErrors();

    $this->actingAs($this->hanna)->get(route('personalverwaltung.digital-id'))->assertOk()
        ->assertSee('12.04.1990')->assertSee('Musterhausen')->assertSee('Gemeinde Musterstadt')->assertSee('MS-4711')
        ->assertSee(now()->addYears(2)->format('m.Y'))->assertSee('Digitale Ausfertigung')->assertSee('FEUERWEHR');

    // The checker sees the fields, the Geburtsdatum only when allowed
    $url = URL::temporarySignedRoute('credentials.check', now()->addMinutes(10), ['user' => $this->hanna->id]);
    auth()->logout();
    $this->get($url)->assertOk()->assertSee('Gemeinde Musterstadt')->assertSee('Musterhausen')->assertDontSee('12.04.1990');
    Livewire::actingAs($this->pv)->test(SettingsPage::class)->set('fields.id_birth_on_check', true)->call('save');
    auth()->logout();
    $this->get($url)->assertOk()->assertSee('12.04.1990');

    // Only with a Ausweisnummer, if the Personalverwaltung wants it
    Livewire::actingAs($this->pv)->test(SettingsPage::class)->set('fields.id_require_number', true)->call('save');
    $bernd = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $this->actingAs($bernd)->get(route('personalverwaltung.digital-id'))->assertNotFound();
    $this->actingAs($this->hanna)->get(route('personalverwaltung.digital-id'))->assertOk();
});

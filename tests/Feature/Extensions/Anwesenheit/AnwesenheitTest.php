<?php

use App\Models\Group;
use App\Models\Module;
use App\Models\Setting;
use App\Models\User;
use App\Services\HoursRegistry;
use Extensions\Anwesenheit\Filament\Resources\TemplateResource;
use Extensions\Anwesenheit\Filament\Resources\TemplateResource\Pages\CreateTemplate;
use Extensions\Anwesenheit\Filament\SettingsPage;
use Extensions\Anwesenheit\Livewire\ListForm;
use Extensions\Anwesenheit\Livewire\ListIndex;
use Extensions\Anwesenheit\Livewire\ListShow;
use Extensions\Anwesenheit\Mail\AttendanceMail;
use Extensions\Anwesenheit\Models\AttendanceList;
use Extensions\Anwesenheit\Models\AttendanceTemplate;
use Extensions\Anwesenheit\Services\AttendanceService;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\StandardRoleAssignments;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function stationOf(string $name): Location
{
    return Location::create(['name' => $name, 'slug' => str($name)->slug()->toString()]);
}

function kamerad(string $name, ?Location $at = null, string $role = 'member', bool $admin = false): User
{
    $user = User::factory()->create(['name' => $name, 'is_admin' => $admin]);
    $user->moduleAccess()->create(['module' => 'anwesenheit']);
    if ($at) {
        $user->forceFill(['ext_location_id' => $at->id])->save();
        $user->locations()->sync([$at->id => ['role' => $role]]);
        $group = Group::withoutGlobalScopes()->where('ext_location_id', $at->id)->first();
        if ($group) {
            $user->groups()->syncWithoutDetaching([$group->id]);
        }
    }

    return $user;
}

beforeEach(function () {
    config(['locations.enabled' => true, 'locations.scoped_role_modules' => []]);
    Module::where('key', 'anwesenheit')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Mail::fake();
    $this->south = stationOf('Wache Süd');
    $this->north = stationOf('Wache Nord');
    $this->southGroup = $this->south->groups()->where('name', 'Einsatzabteilung')->firstOrFail();
    $this->northGroup = $this->north->groups()->where('name', 'Einsatzabteilung')->firstOrFail();
    $this->hanna = kamerad('Hanna Hydrant', $this->south);
    $this->bernd = kamerad('Bernd Blaulicht', $this->south);
    $this->nord = kamerad('Nora Nord', $this->north);
});

it('creates a standalone list without any calendar event, with duration and count', function () {
    Livewire::actingAs($this->hanna)->test(ListForm::class)
        ->set('form.title', 'Übungsabend')
        ->set('form.group_id', (string) $this->southGroup->id)
        ->set('form.starts_at', '2026-10-05T19:00')->set('form.ends_at', '2026-10-05T20:30')
        ->set('form.leader_id', (string) $this->bernd->id)
        ->set("selected.{$this->hanna->id}", true)->set('form.guests', '2')
        ->set('form.recipients', 'wehrfuehrung@example.com')
        ->set('mode', 'submit')->call('save')->assertHasNoErrors()->assertRedirect();

    $list = AttendanceList::sole();
    expect($list->status)->toBe('submitted')
        ->and($list->duration_minutes)->toBe(90)
        ->and($list->participant_count)->toBe(4) // Hanna + leader Bernd + 2 guests
        ->and($list->source_event_id)->toBeNull()
        ->and($list->ext_location_id)->toBe($this->south->id)
        ->and($list->participants()->pluck('users.id')->all())->toEqualCanonicalizing([$this->hanna->id, $this->bernd->id])
        ->and($list->durationLabel())->toBe('1 Std. 30 Min.');

    Mail::assertSent(AttendanceMail::class, fn ($mail) => $mail->hasTo('wehrfuehrung@example.com') && count($mail->attachments()) === 1);
});

it('attaches uploaded files to the mail and rejects bad ones', function () {
    $fill = fn () => Livewire::actingAs($this->hanna)->test(ListForm::class)
        ->set('form.title', 'Unterricht')->set('form.group_id', (string) $this->southGroup->id)
        ->set('form.starts_at', '2026-10-05T19:00')->set('form.ends_at', '2026-10-05T20:00')
        ->set('form.leader_id', (string) $this->bernd->id)->set('form.recipients', 'a@example.com');

    $fill()->set('uploads', [UploadedFile::fake()->create('virus.php', 5)])->call('save')->assertHasErrors('uploads');
    expect(AttendanceList::count())->toBe(0);

    $fill()->set('uploads', [UploadedFile::fake()->image('liste.png')])->call('save')->assertHasNoErrors();

    $list = AttendanceList::sole();
    expect($list->files)->toHaveCount(1);
    Mail::assertSent(AttendanceMail::class, fn ($mail) => count($mail->attachments()) === 2);
});

it('keeps a draft without needing leader or participants and does not mail it', function () {
    Livewire::actingAs($this->hanna)->test(ListForm::class)
        ->set('form.title', 'Entwurf')->set('form.group_id', (string) $this->southGroup->id)
        ->set('mode', 'draft')->call('save')->assertHasNoErrors();

    expect(AttendanceList::sole()->status)->toBe('draft');
    Mail::assertNothingSent();
});

it('requires leader, times and a participant to submit', function () {
    Livewire::actingAs($this->hanna)->test(ListForm::class)
        ->set('form.title', 'Leer')->set('mode', 'submit')->call('save')
        ->assertHasErrors(['form.starts_at', 'form.leader_id', 'selected']);
});

it('refuses an end before the start', function () {
    Livewire::actingAs($this->hanna)->test(ListForm::class)
        ->set('form.title', 'X')->set('form.starts_at', '2026-10-05T20:00')->set('form.ends_at', '2026-10-05T19:00')
        ->set('mode', 'draft')->call('save')->assertHasErrors('form.ends_at');
});

it('keeps lists inside their Standort', function () {
    $list = AttendanceList::create(['title' => 'Süd', 'created_by' => $this->hanna->id, 'ext_location_id' => $this->south->id]);
    $other = AttendanceList::create(['title' => 'Nord', 'created_by' => $this->nord->id, 'ext_location_id' => $this->north->id]);

    $this->actingAs($this->nord)->get(route('anwesenheit.show', $list))->assertNotFound();
    $this->actingAs($this->hanna)->get(route('anwesenheit.show', $other))->assertNotFound();
    $this->actingAs($this->hanna)->get(route('anwesenheit.show', $list))->assertOk();
});

it('lets members only pick people of the Standort of the list', function () {
    Livewire::actingAs($this->hanna)->test(ListForm::class)
        ->set('form.title', 'Süd')->set("selected.{$this->nord->id}", true)->set('mode', 'draft')->call('save')
        ->assertHasErrors('selected');

    // a Standort the user does not belong to is refused too
    Livewire::actingAs($this->hanna)->test(ListForm::class)
        ->set('form.title', 'Nord')->set('form.ext_location_id', (string) $this->north->id)->set('mode', 'draft')->call('save')
        ->assertHasErrors('form.ext_location_id');
});

it('shows members only their own lists and Standort-Admins all lists of their Standort', function () {
    $mine = AttendanceList::create(['title' => 'Meine', 'created_by' => $this->hanna->id, 'ext_location_id' => $this->south->id]);
    $foreign = AttendanceList::create(['title' => 'Berndsliste', 'created_by' => $this->bernd->id, 'ext_location_id' => $this->south->id]);
    $northList = AttendanceList::create(['title' => 'Nordliste', 'created_by' => $this->nord->id, 'ext_location_id' => $this->north->id]);

    Livewire::actingAs($this->hanna)->test(ListIndex::class)->assertSee('Meine')->assertDontSee('Berndsliste')->assertDontSee('Nordliste');

    $admin = kamerad('Walter Wehrführer', $this->south, 'admin');
    expect(AttendanceService::canManage($admin))->toBeFalse();
    Livewire::actingAs($admin)->test(ListIndex::class)->assertSee('Meine')->assertSee('Berndsliste')->assertDontSee('Nordliste');

    $global = kamerad('Gisela Gemeinde', null, 'member', true);
    Livewire::actingAs($global)->test(ListIndex::class)->assertSee('Nordliste')->assertSee('Berndsliste');
    expect(AttendanceService::canManage($this->hanna))->toBeFalse()
        ->and($foreign->exists)->toBeTrue()
        ->and($northList->exists)->toBeTrue();
});

it('prefills from a template without linking the list to it', function () {
    $template = AttendanceTemplate::create(['ext_location_id' => $this->south->id, 'group_id' => $this->southGroup->id, 'name' => 'Montagsübung', 'kind' => 'uebung', 'title' => 'Übungsabend', 'duration_minutes' => 120, 'leader_id' => $this->bernd->id, 'recipients' => 'vorlage@example.com']);

    $form = Livewire::actingAs($this->hanna)->test(ListForm::class)
        ->set('prefill', 'tpl:'.$template->id)->call('applyPrefill')
        ->assertSet('form.title', 'Übungsabend')->assertSet('form.leader_id', (string) $this->bernd->id)->assertSet('form.recipients', 'vorlage@example.com');

    expect(AttendanceTemplate::count())->toBe(1);
    $form->set("selected.{$this->hanna->id}", true)->set('mode', 'submit')->call('save')->assertHasNoErrors();
    expect(AttendanceList::sole()->duration_minutes)->toBe(120);

    // deleting the template does not touch the list
    $template->delete();
    expect(AttendanceList::count())->toBe(1);
});

it('lets only the creator edit a draft and managers correct submitted lists', function () {
    $list = AttendanceList::create(['title' => 'A', 'created_by' => $this->hanna->id, 'ext_location_id' => $this->south->id]);
    $this->actingAs($this->bernd)->get(route('anwesenheit.edit', $list))->assertForbidden();
    $this->actingAs($this->hanna)->get(route('anwesenheit.edit', $list))->assertOk();

    $list->update(['status' => 'submitted']);
    $this->actingAs($this->hanna)->get(route('anwesenheit.edit', $list))->assertForbidden();
    $this->actingAs(kamerad('Walter Wehrführer', $this->south, 'admin'))->get(route('anwesenheit.edit', $list))->assertOk();
});

it('renders the PDF and serves uploads only to people who may see the list', function () {
    $list = AttendanceList::create(['title' => 'PDF', 'created_by' => $this->hanna->id, 'ext_location_id' => $this->south->id, 'starts_at' => now(), 'ends_at' => now()->addHour()]);
    $list->participants()->attach($this->hanna->id);
    $file = $list->files()->create(['filename' => 'liste.txt', 'size' => 5, 'data' => base64_encode('hallo')]);

    $this->actingAs($this->hanna)->get(route('anwesenheit.pdf', $list))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs($this->hanna)->get(route('anwesenheit.file', [$list, $file]))->assertOk();
    $this->actingAs($this->bernd)->get(route('anwesenheit.pdf', $list))->assertForbidden();
});

it('resends the mail and reports a missing recipient', function () {
    $list = AttendanceList::create(['title' => 'Mail', 'created_by' => $this->hanna->id, 'ext_location_id' => $this->south->id, 'status' => 'submitted', 'starts_at' => now(), 'ends_at' => now()->addHour()]);

    Livewire::actingAs($this->hanna)->test(ListShow::class, ['list' => $list])->call('resend')->assertSet('flash', 'Es ist kein E-Mail-Empfänger hinterlegt.');
    $list->update(['recipients' => 'a@example.com, kaputt']);
    Livewire::actingAs($this->hanna)->test(ListShow::class, ['list' => $list])->call('resend')->assertSet('flash', 'Die E-Mail mit PDF wurde versendet.');
    Mail::assertSent(AttendanceMail::class, 1);
    expect($list->fresh()->mailed_at)->not->toBeNull();
});

it('feeds the Stundennachweis and keeps drafts out of it', function () {
    $done = AttendanceList::create(['title' => 'Fertig', 'created_by' => $this->hanna->id, 'status' => 'submitted', 'starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addMinutes(90), 'duration_minutes' => 90]);
    $done->participants()->attach($this->hanna->id);
    $draft = AttendanceList::create(['title' => 'Entwurf', 'created_by' => $this->hanna->id, 'starts_at' => now()->subDay(), 'duration_minutes' => 60]);
    $draft->participants()->attach($this->hanna->id);

    $rows = collect(app(HoursRegistry::class)->rows([$this->hanna->id], now()->subWeek(), now()));
    expect($rows->pluck('title')->all())->toContain('Fertig')->not->toContain('Entwurf');
});

it('is off for users without module access', function () {
    $outsider = User::factory()->create();
    $this->actingAs($outsider)->get(route('anwesenheit.index'))->assertForbidden();
});

it('puts the offline draft markers on the form fields', function () {
    $this->actingAs($this->hanna)->get(route('anwesenheit.create'))->assertOk()
        ->assertSee('data-offline-draft="anwesenheit-list"', false)->assertSee('data-offline-draft-field', false)
        ->assertDontSee('localStorage');
});

it('keeps global settings writable only by active global administrators', function () {
    Setting::set('anwesenheit.recipients', 'listen@example.com');
    $manager = kamerad('Walter Wehrführer', $this->south, 'admin');
    $this->actingAs($manager)->get('/admin/anwesenheit/einstellungen')->assertForbidden();
    Livewire::actingAs($manager)->test(SettingsPage::class)->call('save')->assertNotFound();
    expect(AttendanceService::defaultRecipients())->toBe('listen@example.com');

    $global = kamerad('Gisela Gemeinde', null, 'member', true);
    Livewire::actingAs($global)->test(SettingsPage::class)
        ->fillForm(['recipients' => 'verwaltung@example.com'])->call('save')->assertHasNoFormErrors();
    expect(AttendanceService::defaultRecipients())->toBe('verwaltung@example.com');
});

it('preserves global administration of every list and template', function () {
    $global = kamerad('Gisela Gemeinde', null, 'member', true);
    $service = app(AttendanceService::class);
    foreach ([$this->south, $this->north] as $location) {
        $list = AttendanceList::create(['title' => 'Übung', 'created_by' => $this->hanna->id, 'ext_location_id' => $location->id, 'status' => 'submitted']);
        $template = AttendanceTemplate::create(['name' => 'Übung', 'kind' => 'uebung', 'ext_location_id' => $location->id]);
        $this->actingAs($global);
        expect(AttendanceService::canSee($global, $list))->toBeTrue()
            ->and(AttendanceService::canEdit($global, $list))->toBeTrue()
            ->and(AttendanceService::canResend($global, $list))->toBeTrue()
            ->and(TemplateResource::canEdit($template))->toBeTrue()
            ->and($service->visibleTo($global)->whereKey($list->id)->exists())->toBeTrue();
        $service->delete($global, $list);
        expect($list->exists)->toBeFalse();
    }
});

it('keeps participant permissions read only and creator draft permissions intact', function () {
    $list = AttendanceList::create(['title' => 'Übung', 'created_by' => $this->hanna->id, 'ext_location_id' => $this->south->id]);
    $list->participants()->attach($this->bernd->id);
    $this->actingAs($this->bernd);
    expect(AttendanceService::canSee($this->bernd, $list))->toBeTrue()
        ->and(AttendanceService::canEdit($this->bernd, $list))->toBeFalse()
        ->and(AttendanceService::canResend($this->bernd, $list))->toBeFalse()
        ->and(AttendanceService::canEdit($this->hanna, $list))->toBeTrue();
    expect(fn () => app(AttendanceService::class)->delete($this->bernd, $list))->toThrow(ValidationException::class);
    $list->update(['status' => 'submitted']);
    expect(AttendanceService::canEdit($this->hanna, $list))->toBeFalse()
        ->and(AttendanceService::canResend($this->hanna, $list))->toBeTrue();
});

it('requires current target permissions for management contracts', function () {
    $list = AttendanceList::create(['title' => 'Übung', 'created_by' => $this->hanna->id, 'ext_location_id' => $this->south->id]);
    $template = AttendanceTemplate::create(['name' => 'Übung', 'kind' => 'uebung', 'ext_location_id' => $this->south->id]);
    foreach (['member' => false, 'admin' => true] as $role => $allowed) {
        $user = kamerad('Walter Wehrführer', $this->south, $role);
        $this->actingAs($user);
        expect(AttendanceService::canManageAt($user, $this->south->id))->toBe($allowed)
            ->and(AttendanceService::canEdit($user, $list))->toBe($allowed)
            ->and(TemplateResource::canEdit($template))->toBe($allowed)
            ->and(TemplateResource::canDelete($template))->toBe($allowed);
    }
});

it('resolves management contracts independently for each assigned location', function () {
    $manager = kamerad('Walter Wehrführer', $this->south, 'admin');
    DB::table('x_location_user')->updateOrInsert(
        ['location_id' => $this->north->id, 'user_id' => $manager->id],
        ['role' => 'member'],
    );
    $list = AttendanceList::create(['title' => 'Übung', 'created_by' => $this->nord->id, 'ext_location_id' => $this->north->id, 'status' => 'submitted']);
    $template = AttendanceTemplate::create(['name' => 'Übung', 'kind' => 'uebung', 'ext_location_id' => $this->north->id]);
    $this->actingAs($manager);

    expect(AttendanceService::canManageAt($manager, $this->south->id))->toBeTrue()
        ->and(AttendanceService::canManageAt($manager, $this->north->id))->toBeFalse()
        ->and(AttendanceService::canSee($manager, $list))->toBeFalse()
        ->and(AttendanceService::canEdit($manager, $list))->toBeFalse()
        ->and(AttendanceService::canResend($manager, $list))->toBeFalse()
        ->and(TemplateResource::canEdit($template))->toBeFalse()
        ->and(TemplateResource::getEloquentQuery()->whereKey($template->id)->exists())->toBeFalse()
        ->and(app(AttendanceService::class)->visibleTo($manager)->whereKey($list->id)->exists())->toBeFalse();
});

it('drops revoked location permissions for already loaded records', function () {
    $manager = kamerad('Walter Wehrführer', $this->south, 'admin');
    DB::table('x_location_user')->updateOrInsert(
        ['location_id' => $this->north->id, 'user_id' => $manager->id],
        ['role' => 'admin'],
    );
    $list = AttendanceList::create(['title' => 'Übung', 'created_by' => $this->nord->id, 'ext_location_id' => $this->north->id]);
    $this->actingAs($manager);
    expect(AttendanceService::canEdit($manager, $list))->toBeTrue();

    DB::table('x_location_user')->where('location_id', $this->north->id)->where('user_id', $manager->id)->delete();
    expect(AttendanceService::canSee($manager, $list))->toBeFalse()
        ->and(AttendanceService::canEdit($manager, $list))->toBeFalse()
        ->and(app(AttendanceService::class)->visibleTo($manager)->whereKey($list->id)->exists())->toBeFalse();
});

it('rechecks read permission on subsequent component renders', function () {
    $list = AttendanceList::create(['title' => 'Übung', 'created_by' => $this->hanna->id, 'ext_location_id' => $this->south->id]);
    $list->participants()->attach($this->bernd->id);
    $component = Livewire::actingAs($this->bernd)->test(ListShow::class, ['list' => $list])->assertOk();
    $list->participants()->detach($this->bernd->id);
    $component->call('$refresh')->assertForbidden();
});

it('limits youth and children wardens to assigned groups for lists, members, and templates', function () {
    $youth = $this->south->groups()->where('name', 'Jugendfeuerwehr')->firstOrFail();
    $children = $this->south->groups()->where('name', 'Kinderfeuerwehr')->firstOrFail();
    $warden = kamerad('Jana Jugendwart', $this->south);
    $youthMember = kamerad('Yara Jugend', $this->south);
    $childrenMember = kamerad('Karla Kinder', $this->south);
    $youthMember->groups()->sync([$youth->id]);
    $childrenMember->groups()->sync([$children->id]);
    $grant = app(StandardRoleAssignments::class)->grant(
        User::factory()->admin()->create(),
        $warden->id,
        'jugendwart',
        $this->south->id,
        $youth->id,
        'Leitung Jugendgruppe',
    );

    $youthList = AttendanceList::create([
        'title' => 'Jugendübung', 'created_by' => $this->hanna->id,
        'ext_location_id' => $this->south->id, 'group_id' => $youth->id,
    ]);
    $childrenList = AttendanceList::create([
        'title' => 'Kinderübung', 'created_by' => $this->hanna->id,
        'ext_location_id' => $this->south->id, 'group_id' => $children->id,
    ]);
    $legacyList = AttendanceList::create([
        'title' => 'Alte Liste', 'created_by' => $this->hanna->id,
        'ext_location_id' => $this->south->id,
    ]);
    $youthTemplate = AttendanceTemplate::create([
        'name' => 'Jugendvorlage', 'kind' => 'uebung', 'ext_location_id' => $this->south->id, 'group_id' => $youth->id,
    ]);
    $childrenTemplate = AttendanceTemplate::create([
        'name' => 'Kindervorlage', 'kind' => 'uebung', 'ext_location_id' => $this->south->id, 'group_id' => $children->id,
    ]);
    $this->actingAs($warden);
    $service = app(AttendanceService::class);

    expect($service->groupOptions($warden, $this->south->id))->toBe([$youth->id => 'Jugendfeuerwehr (Wache Süd)'])
        ->and($service->members($this->south->id, $youth->id)->pluck('id')->all())->toBe([$youthMember->id])
        ->and($service->visibleTo($warden)->pluck('id')->all())->toBe([$youthList->id])
        ->and(AttendanceService::canSee($warden, $youthList))->toBeTrue()
        ->and(AttendanceService::canSee($warden, $childrenList))->toBeFalse()
        ->and(AttendanceService::canSee($warden, $legacyList))->toBeFalse()
        ->and(TemplateResource::getEloquentQuery()->pluck('id')->all())->toBe([$youthTemplate->id])
        ->and(TemplateResource::canEdit($youthTemplate))->toBeTrue()
        ->and(TemplateResource::canEdit($childrenTemplate))->toBeFalse();

    Livewire::actingAs($warden)->test(ListForm::class)
        ->set('form.title', 'Neue Jugendübung')->set("selected.{$youthMember->id}", true)
        ->set('mode', 'draft')->call('save')->assertHasNoErrors();
    expect(AttendanceList::where('title', 'Neue Jugendübung')->value('group_id'))->toBe($youth->id);

    Livewire::actingAs($warden)->test(ListForm::class, ['list' => $youthList])
        ->set('form.group_id', (string) $children->id)
        ->set("selected.{$childrenMember->id}", true)
        ->set('mode', 'draft')->call('save')->assertHasErrors('form.group_id');

    app(StandardRoleAssignments::class)->revoke(User::factory()->admin()->create(), $grant->id, 'Aufgabe beendet');
    expect(AttendanceService::canSee($warden, $youthList))->toBeFalse()
        ->and(AttendanceService::canEdit($warden, $youthList))->toBeFalse()
        ->and($service->visibleTo($warden)->whereKey($youthList->id)->exists())->toBeFalse();
});

it('lets municipality wardens manage only matching groups across active locations', function () {
    $youthNorth = $this->north->groups()->where('name', 'Jugendfeuerwehr')->firstOrFail();
    $youthSouth = $this->south->groups()->where('name', 'Jugendfeuerwehr')->firstOrFail();
    $childrenSouth = $this->south->groups()->where('name', 'Kinderfeuerwehr')->firstOrFail();
    $warden = kamerad('Jana Gemeindejugendwart');
    app(StandardRoleAssignments::class)->grant(
        User::factory()->admin()->create(),
        $warden->id,
        'gemeindejugendwart',
        null,
        null,
        'Gemeindeweite Jugendverwaltung',
    );
    $northList = AttendanceList::create([
        'title' => 'Nordjugend', 'created_by' => $this->hanna->id,
        'ext_location_id' => $this->north->id, 'group_id' => $youthNorth->id,
    ]);
    $southList = AttendanceList::create([
        'title' => 'Südjugend', 'created_by' => $this->hanna->id,
        'ext_location_id' => $this->south->id, 'group_id' => $youthSouth->id,
    ]);
    $childrenList = AttendanceList::create([
        'title' => 'Südkinder', 'created_by' => $this->hanna->id,
        'ext_location_id' => $this->south->id, 'group_id' => $childrenSouth->id,
    ]);
    $service = app(AttendanceService::class);
    $main = Location::default();
    $youthMain = $main->groups()->where('name', 'Jugendfeuerwehr')->firstOrFail();

    expect($service->groupOptions($warden))->toBe([
        $youthMain->id => 'Jugendfeuerwehr (Hauptstandort)',
        $youthNorth->id => 'Jugendfeuerwehr (Wache Nord)',
        $youthSouth->id => 'Jugendfeuerwehr (Wache Süd)',
    ])
        ->and($service->visibleTo($warden)->pluck('id')->all())->toEqualCanonicalizing([$northList->id, $southList->id])
        ->and(AttendanceService::canEdit($warden, $northList))->toBeTrue()
        ->and(AttendanceService::canEdit($warden, $southList))->toBeTrue()
        ->and(AttendanceService::canEdit($warden, $childrenList))->toBeFalse();
});

it('keeps group-scoped attendance permissions when the optional location role adapter is enabled', function () {
    config(['locations.scoped_role_modules' => ['anwesenheit']]);
    $youth = $this->south->groups()->where('name', 'Jugendfeuerwehr')->firstOrFail();
    $children = $this->south->groups()->where('name', 'Kinderfeuerwehr')->firstOrFail();
    $warden = kamerad('Jana Jugendwart', $this->south);
    $grant = app(StandardRoleAssignments::class)->grant(
        User::factory()->admin()->create(),
        $warden->id,
        'jugendwart',
        $this->south->id,
        $youth->id,
        'Leitung Jugendgruppe',
    );
    $youthList = AttendanceList::create([
        'title' => 'Jugendübung', 'created_by' => $this->hanna->id,
        'ext_location_id' => $this->south->id, 'group_id' => $youth->id,
    ]);
    $childrenList = AttendanceList::create([
        'title' => 'Kinderübung', 'created_by' => $this->hanna->id,
        'ext_location_id' => $this->south->id, 'group_id' => $children->id,
    ]);

    expect(AttendanceService::canManage($warden))->toBeTrue()
        ->and(AttendanceService::canEdit($warden, $youthList))->toBeTrue()
        ->and(AttendanceService::canEdit($warden, $childrenList))->toBeFalse()
        ->and(app(AttendanceService::class)->visibleTo($warden)->pluck('id')->all())->toBe([$youthList->id]);

    app(StandardRoleAssignments::class)->revoke(User::factory()->admin()->create(), $grant->id, 'Aufgabe beendet');
    expect(AttendanceService::canManage($warden))->toBeFalse()
        ->and(AttendanceService::canEdit($warden, $youthList))->toBeFalse();
});

it('rejects template targets whose group belongs to another location', function () {
    $foreignGroup = $this->north->groups()->where('name', 'Jugendfeuerwehr')->firstOrFail();
    $admin = User::factory()->admin()->create();

    expect(app(AttendanceService::class)->canSelectGroup($admin, $this->south->id, $foreignGroup->id))->toBeFalse();

    Livewire::actingAs($admin)->test(CreateTemplate::class)
        ->fillForm([
            'name' => 'Falsche Zuordnung',
            'kind' => 'uebung',
            'ext_location_id' => $this->south->id,
            'group_id' => $foreignGroup->id,
        ])
        ->call('create')
        ->assertHasFormErrors(['group_id']);

    expect(AttendanceTemplate::where('name', 'Falsche Zuordnung')->exists())->toBeFalse();
});

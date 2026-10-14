<?php

use App\Livewire\Dashboard\Home;
use App\Models\Module;
use App\Models\Place;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Objektplaene\src\Services\ObjectPlanService;
use Modules\Schluessel\src\Livewire\KeyDetail;
use Modules\Schluessel\src\Livewire\KeyForm;
use Modules\Schluessel\src\Livewire\MyKeys;
use Modules\Schluessel\src\Models\Handover;
use Modules\Schluessel\src\Models\Key;
use Modules\Schluessel\src\Notifications\KeyIssuedNotification;
use Modules\Schluessel\src\Notifications\KeyLostNotification;
use Modules\Schluessel\src\Notifications\KeyOverdueNotification;
use Modules\Schluessel\src\Services\KeyService;

beforeEach(function () {
    Module::whereIn('key', ['schluessel', 'objektplaene', 'fahrzeuge', 'personalverwaltung'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Notification::fake();
    $this->travelTo(Carbon::parse('2027-05-10 10:00', 'Europe/Berlin'));

    $this->wart = User::factory()->create(['name' => 'Sven Schlüsselwart']);
    $this->wart->moduleAccess()->create(['module' => 'schluessel', 'role' => 'schluesselwart']);
    foreach (['hanna' => 'Hanna Hydrant', 'bernd' => 'Bernd Blaulicht'] as $key => $name) {
        $this->{$key} = User::factory()->create(['name' => $name]);
        $this->{$key}->moduleAccess()->create(['module' => 'schluessel']);
    }
    $this->station = Place::create(['name' => 'Gerätehaus Musterstadt', 'address' => 'Hauptstraße 1, 12345 Musterstadt']);
    $this->hlf = Vehicle::factory()->create(['name' => 'HLF 20', 'is_active' => true]);
    $this->k = app(KeyService::class);
});

test('the Schlüsselwart keeps the keys with what they open; Kameraden only see their own', function () {
    Livewire::actingAs($this->wart)->test(KeyForm::class)
        ->set('form.name', '')->call('save')->assertHasErrors('name')
        ->set('form.name', 'Gerätehaus Haupteingang')->set('form.locking', 'Gruppe A')->set('form.place_id', (string) $this->station->id)
        ->set('form.storage', 'Schlüsselkasten Wachraum')->call('save')->assertRedirect();

    $key = Key::sole();
    expect($key)->code->toBe('SL-0001')->opensLabel()->toBe('Gerätehaus Musterstadt · Gruppe A')
        ->and($this->k->nextCode())->toBe('SL-0002');
    $this->actingAs($this->hanna)->get(route('schluessel.list'))->assertForbidden();
    $this->actingAs($this->hanna)->get(route('schluessel.show', $key))->assertForbidden();
    $this->actingAs($this->wart)->get(route('schluessel.list'))->assertOk()->assertSee('Gerätehaus Haupteingang')
        ->assertSee('href="'.route('schluessel.list').'"', false);
});

test('handed out with a signed Schlüsselquittung, once at a time; reminders after the return date', function () {
    $key = $this->k->saveKey($this->wart, null, ['name' => 'Jugendraum', 'kind' => 'transponder']);

    Livewire::actingAs($this->wart)->test(KeyDetail::class, ['item' => $key])
        ->set('memberId', (string) $this->hanna->id)->set('dueOn', '2027-05-20')->set('note', 'für die Jugendgruppe')
        ->set('signature', signaturePng())->call('issue')->assertSee('Ausgegeben an Hanna Hydrant')->assertSee('Zurückgenommen');

    $handover = Handover::sole();
    expect($handover->signature)->not->toBeNull()->and($key->fresh()->statusLabel())->toBe('Ausgegeben');
    Notification::assertSentTo($this->hanna, KeyIssuedNotification::class);
    expect(fn () => $this->k->issue($this->wart, $key->fresh(), $this->bernd))->toThrow(ValidationException::class);

    $this->actingAs($this->hanna)->get(route('schluessel.receipt', $handover))->assertOk()->assertSee('Schlüsselquittung')->assertSee('data:image/png;base64,', false);
    $this->actingAs($this->bernd)->get(route('schluessel.receipt', $handover))->assertForbidden();
    Livewire::actingAs($this->hanna)->test(MyKeys::class)->assertSee('Jugendraum')->assertSee('Rückgabe bis 20.05.2027');

    $this->travelTo(Carbon::parse('2027-05-21 08:00', 'Europe/Berlin'));
    $this->artisan('schluessel:remind')->assertSuccessful();
    Notification::assertSentTo($this->hanna, KeyOverdueNotification::class);
    Notification::fake();
    $this->artisan('schluessel:remind')->assertSuccessful();
    Notification::assertNothingSent();
    Livewire::actingAs($this->hanna)->test(Home::class)->assertSee('1 Schlüssel zurückgeben');
    Livewire::actingAs($this->wart)->test(Home::class)->assertSee('1 Schlüssel überfällig');

    $this->k->takeBack($this->wart, $key->fresh());
    expect($handover->fresh())->returned_at->not->toBeNull()->returned_to->toBe($this->wart->id)
        ->and($key->fresh()->statusLabel())->toBe('Vorhanden');
});

test('lost keys reach the Schlüsselwart; keys at former members show up; other modules show their keys', function () {
    $object = app(ObjectPlanService::class)->saveObject(User::factory()->admin()->create(), null, ['name' => 'Grundschule Musterstadt', 'object_type' => 'schule', 'hazard_class' => 'normal', 'has_fsd' => true]);
    $fsd = $this->k->saveKey($this->wart, null, ['name' => 'FSD Grundschule', 'kind' => 'schluessel', 'object_id' => $object->id, 'storage' => 'im Schlüsseldepot']);
    $car = $this->k->saveKey($this->wart, null, ['name' => 'HLF Ersatzschlüssel', 'kind' => 'schluessel', 'vehicle_id' => $this->hlf->id]);
    $this->k->issue($this->wart, $car, $this->hanna);

    Livewire::actingAs($this->hanna)->test(MyKeys::class)->call('reportLost', Handover::sole()->id)->assertSee('Verlust gemeldet');
    Notification::assertSentTo($this->wart, KeyLostNotification::class);
    expect($car->fresh()->status)->toBe('lost');
    expect(fn () => $this->k->reportLost($this->bernd, $fsd))->toThrow(ValidationException::class);

    $this->hanna->update(['status' => 'left']);
    $this->actingAs($this->wart)->get(route('schluessel.list'))->assertSee('nicht mehr aktiv sind')->assertSee('Hanna Hydrant (Ausgetreten)');

    // Objektpläne, Fahrzeuge and Personalverwaltung show the keys; who has one only for the Schlüsselwart
    $viewer = User::factory()->create();
    foreach (['objektplaene', 'schluessel'] as $m) {
        $viewer->moduleAccess()->create(['module' => $m]);
    }
    $this->actingAs($viewer)->get(route('objektplaene.object', $object))->assertSee('FSD Grundschule')->assertSee('im Schlüsseldepot');
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->get(route('fahrzeuge.show', $this->hlf))->assertSee('HLF Ersatzschlüssel')->assertSee('Verloren');
    $this->actingAs($admin)->get(route('personalverwaltung.member', $this->hanna))->assertSee('HLF Ersatzschlüssel');

    // The label: Schlüsselwart to the key, everyone else to their own keys
    $this->actingAs($this->wart)->get('/i/'.$fsd->code)->assertRedirect(route('schluessel.show', $fsd));
    $this->actingAs($this->bernd)->get('/i/'.$fsd->code)->assertRedirect(route('schluessel.index'));
});

test('"Admin" is for admins only; the Schlüsselwart reaches all keys through the Modulleiste', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $html = $this->actingAs($admin)->get(route('schluessel.list'))->assertOk()->getContent();
    expect($html)->toContain('href="'.route('filament.admin.pages.dashboard').'"');

    $html = $this->actingAs($this->wart)->get(route('schluessel.index'))->assertOk()->getContent();
    expect($html)->not->toContain('>Admin</a>')->toContain('Alle Schlüssel')->toContain('aria-label="Neuer Schlüssel"');
    $html = $this->actingAs($this->hanna)->get(route('schluessel.index'))->assertOk()->getContent();
    expect($html)->not->toContain('Alle Schlüssel')->not->toContain('aria-label="Neuer Schlüssel"');
});

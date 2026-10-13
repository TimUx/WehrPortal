<?php

use App\Livewire\Dashboard\Home;
use App\Models\Module;
use App\Models\User;
use App\Services\DriverRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\Fahrtenbuch\src\Livewire\TripForm;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fuehrerscheine\src\Livewire\MyLicense;
use Modules\Fuehrerscheine\src\Livewire\Review;
use Modules\Fuehrerscheine\src\Models\License;
use Modules\Fuehrerscheine\src\Models\Photo;
use Modules\Fuehrerscheine\src\Notifications\LicenseDecidedNotification;
use Modules\Fuehrerscheine\src\Notifications\LicenseReminderNotification;
use Modules\Fuehrerscheine\src\Notifications\LicenseSubmittedNotification;
use Modules\Fuehrerscheine\src\Services\LicenseService;
use Modules\Kalender\src\Models\Event;

beforeEach(function () {
    Module::whereIn('key', ['fuehrerscheine', 'fahrzeuge', 'fahrtenbuch', 'kalender'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Notification::fake();
    $this->pruefer = User::factory()->create(['name' => 'Paula Prüfer']);
    $this->pruefer->moduleAccess()->create(['module' => 'fuehrerscheine', 'role' => 'fuehrerscheinpruefer']);
    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant']);
    foreach (['fuehrerscheine', 'fahrtenbuch', 'kalender'] as $m) {
        $this->hanna->moduleAccess()->create(['module' => $m]);
    }
    $this->s = app(LicenseService::class);

    // MTW 3,5 t, TSF-W 4,75 t, HLF 15 t
    $vehicle = function (string $name, int $kg, ?string $class = null) {
        $v = Vehicle::factory()->create(['name' => $name, 'is_active' => true]);
        DB::table('fv_profiles')->insert(['vehicle_id' => $v->id, 'gross_weight_kg' => $kg, 'license_class' => $class, 'created_at' => now(), 'updated_at' => now()]);

        return $v;
    };
    $this->mtw = $vehicle('MTW', 3500);
    $this->tsf = $vehicle('TSF-W', 4750);
    $this->hlf = $vehicle('HLF 20', 15000, 'C');
});

function photo(string $name): UploadedFile
{
    return UploadedFile::fake()->image($name, 400, 250);
}

test('a Kamerad submits classes and photos, the Prüfer validates, the photos are gone', function () {
    $this->travelBack();   // Livewire's temporary uploads are signed with the real clock
    Livewire::actingAs($this->hanna)->test(MyLicense::class)
        ->set('classes.B.on', true)->set('classes.C1.on', true)->set('documentValidUntil', now()->addYears(10)->toDateString())
        ->set('front', photo('vorne.jpg'))->set('back', photo('hinten.jpg'))->call('submit')->assertHasErrors('classes')   // C1 without date
        ->set('classes.C1.valid_until', now()->addYears(3)->toDateString())->set('classes.FW475.on', true)->call('submit')->assertHasNoErrors()
        ->assertSee('Wartet auf Prüfung');
    $license = License::sole();
    expect($license->status)->toBe('pending')->and(Photo::count())->toBe(2);
    Notification::assertSentTo($this->pruefer, LicenseSubmittedNotification::class);

    $review = Livewire::actingAs($this->pruefer)->test(Review::class)->assertSee('Hanna Hydrant')->assertSee('C1 bis');
    $this->actingAs($this->pruefer)->get(route('fuehrerscheine.photo', Photo::first()))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $review->call('validateLicense', $license->id)->assertSee('bestätigt');

    expect($license->fresh())->status->toBe('validated')->next_check_on->toDateString()->toBe(LicenseService::today()->addMonths(6)->toDateString())
        ->and(Photo::count())->toBe(0)
        ->and($license->checks()->sole()->snapshot['classes'])->toHaveCount(3);
    Notification::assertSentTo($this->hanna, LicenseDecidedNotification::class);

    // Dates in the own Kalender, only for her
    $events = Event::where('managed_by', 'fuehrerscheine')->pluck('title');
    expect($events)->toContain('Klasse C1 läuft ab')->toContain('Führerscheinprüfung fällig')->toContain('Führerschein (Karte) läuft ab');

    // Nachweise: the Fahrerlaubnis card
    $this->actingAs($this->hanna)->get(route('credentials'))->assertSee('Fahrerlaubnis')->assertSee('Feuerwehr-Fahrberechtigung bis 4,75 t');
});

test('a card that has run out is not validated; rejecting needs a reason', function () {
    $l = License::create(['user_id' => $this->hanna->id, 'classes' => [['class' => 'B', 'valid_until' => null]], 'document_valid_until' => now()->subDay()->toDateString(), 'status' => 'pending', 'submitted_at' => now()]);
    Livewire::actingAs($this->pruefer)->test(Review::class)
        ->call('validateLicense', $l->id)->assertHasErrors('license-'.$l->id)
        ->call('rejectLicense', $l->id)->assertHasErrors('license-'.$l->id)
        ->set('reasons.'.$l->id, 'Foto der Rückseite unscharf')->call('rejectLicense', $l->id)->assertSee('zurückgewiesen');
    expect($l->fresh())->status->toBe('rejected')->rejected_reason->toBe('Foto der Rückseite unscharf');
    Livewire::actingAs($this->hanna)->test(Home::class)->assertSee('Führerschein zurückgewiesen');
    $this->actingAs($this->hanna)->get(route('fuehrerscheine.review'))->assertForbidden();
});

test('who may drive which vehicle, and the warning in the Fahrtenbuch', function () {
    $validated = fn (User $u, array $classes) => License::create(['user_id' => $u->id, 'classes' => $classes, 'status' => 'validated', 'validated_at' => now(), 'next_check_on' => now()->addMonths(6)]);
    $validated($this->hanna, [['class' => 'B', 'valid_until' => null], ['class' => 'FW475', 'valid_until' => null]]);
    $bernd = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $validated($bernd, [['class' => 'B', 'valid_until' => null], ['class' => 'C', 'valid_until' => now()->subDay()->toDateString()], ['class' => 'C1', 'valid_until' => now()->addYear()->toDateString()]]);

    $drivers = app(DriverRegistry::class);
    expect($drivers->drivers($this->tsf->id)['users']->pluck('name')->all())->toBe(['Bernd Blaulicht', 'Hanna Hydrant'])
        ->and($drivers->drivers($this->hlf->id)['users'])->toHaveCount(0)   // Bernd's C has run out
        ->and($drivers->warning($this->hanna, $this->mtw->id))->toBeNull()
        ->and($drivers->warning($this->hanna, $this->hlf->id))->toContain('keine gültige Fahrerlaubnis für HLF 20 (C)')
        ->and($drivers->warning($this->pruefer, $this->mtw->id))->toContain('kein Führerschein hinterlegt');

    Livewire::actingAs($this->hanna)->withQueryParams(['fahrzeug' => $this->hlf->id])->test(TripForm::class)->assertSee('keine gültige Fahrerlaubnis');

    // Without the module, nobody is asked
    Module::where('key', 'fuehrerscheine')->update(['is_active' => false]);
    Cache::forget('modules_active_keys');
    expect($drivers->warning($this->hanna, $this->hlf->id))->toBeNull();
});

test('reminders before a class runs out and when the check is due, once each', function () {
    $this->travelTo(Carbon::parse('2027-06-01 08:00', 'Europe/Berlin'));
    License::create(['user_id' => $this->hanna->id, 'classes' => [['class' => 'C1', 'valid_until' => '2027-07-15']], 'status' => 'validated',
        'validated_at' => now()->subMonths(6), 'next_check_on' => '2027-06-01']);
    $this->artisan('fuehrerscheine:remind')->assertSuccessful();
    Notification::assertSentTo($this->hanna, LicenseReminderNotification::class, fn ($n, $c, $u) => str_contains(implode(' ', $n->toMail($u)->introLines), 'Klasse C1 läuft ab am 15.07.2027')
        && str_contains(implode(' ', $n->toMail($u)->introLines), 'Führerscheinprüfung ist fällig'));
    Notification::fake();
    $this->artisan('fuehrerscheine:remind')->assertSuccessful();
    Notification::assertNothingSent();
});

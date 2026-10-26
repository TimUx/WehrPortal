<?php

use App\Models\User;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\LocationAccess;
use Extensions\Locations\Services\LocationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->withoutVite();
});

function contextLocation(string $name, bool $active = true): Location
{
    return Location::create(['name' => $name, 'slug' => str($name)->slug()->toString(), 'is_active' => $active]);
}

function contextMember(Location $primary, ?Location $additional = null, bool $admin = false): User
{
    $user = User::factory()->create(['is_admin' => $admin, 'ext_location_id' => $primary->id]);
    if ($additional) {
        DB::table('x_location_user')->insert(['user_id' => $user->id, 'location_id' => $additional->id, 'role' => 'member']);
    }

    return $user;
}

it('shows a labelled keyboard and no-JS creation context form outside the global header', function () {
    $south = contextLocation('Wache Süd');
    $north = contextLocation('Wache Nord');
    $outside = contextLocation('Nicht zugeordnet');
    $inactive = contextLocation('Stillgelegt', false);
    $user = contextMember($south, $north);
    DB::table('x_location_user')->insert(['user_id' => $user->id, 'location_id' => $inactive->id, 'role' => 'member']);

    $response = $this->actingAs($user)->get('/')->assertOk()
        ->assertSee('Standort für neue Datensätze:')
        ->assertSee('Wache Süd')->assertSee('Wache Nord')
        ->assertDontSee($outside->name)->assertDontSee($inactive->name)
        ->assertSee('Berechtigungen und sichtbare Daten richten sich weiterhin nach deinen Mitgliedschaften und Rollen.');

    $html = $response->getContent();
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    $xpath = new DOMXPath($dom);
    expect($xpath->query('//header//form[contains(@action, "/standort/wechseln")]')->length)->toBe(0)
        ->and($xpath->query('//section[@aria-label="Standortkontext"]//form[@method="POST"]')->length)->toBe(1)
        ->and($xpath->query('//label[@for="app-location-select"]')->length)->toBe(1)
        ->and($xpath->query('//select[@id="app-location-select"][@aria-describedby="app-location-help"]')->length)->toBe(1)
        ->and($xpath->query('//select[@id="app-location-select"]/@onchange')->length)->toBe(0)
        ->and($xpath->query('//section[@aria-label="Standortkontext"]//button[@type="submit"]')->length)->toBe(1)
        ->and($xpath->query('//section[@aria-label="Standortkontext"]//input[@name="_token"]')->length)->toBe(1)
        ->and(strpos($html, 'aria-label="Standortkontext"'))->toBeGreaterThan(strpos($html, '</header>'));
});

it('shows a single primary membership without a pointless switch form', function () {
    $south = contextLocation('Wache Süd');
    $user = contextMember($south);

    $this->actingAs($user)->get('/')->assertOk()
        ->assertSee('Standort für neue Datensätze:')->assertSee($south->name)
        ->assertDontSee('id="app-location-select"', false);
    expect(app(LocationAccess::class)->roles($user))->toHaveKey($south->id);
});

it('switches through the existing POST and uses the selection only as a creation default', function () {
    $south = contextLocation('Wache Süd');
    $north = contextLocation('Wache Nord');
    $user = contextMember($south, $north);
    $southVehicle = Vehicle::factory()->create(['ext_location_id' => $south->id]);
    $northVehicle = Vehicle::factory()->create(['ext_location_id' => $north->id]);

    $this->actingAs($user)->from(route('profile'))->post(route('locations.switch'), ['location_id' => $north->id])
        ->assertRedirect(route('profile'))->assertSessionHas('ext_location_id', $north->id);
    $this->get('/')->assertOk()->assertSee('value="'.$north->id.'" selected', false);

    expect(app(LocationAccess::class)->currentLocationId($user))->toBe($north->id)
        ->and(Vehicle::pluck('id')->all())->toEqualCanonicalizing([$southVehicle->id, $northVehicle->id])
        ->and(Vehicle::factory()->create()->ext_location_id)->toBe($north->id);
});

it('requires a matching CSRF token on the real switch route', function () {
    $south = contextLocation('Wache Süd');
    $user = contextMember($south);
    $this->actingAs($user);
    $this->app->instance('env', 'production');

    try {
        $this->post(route('locations.switch'), ['location_id' => $south->id])->assertStatus(419);
        $token = str_repeat('a', 40);
        $this->withSession(['_token' => $token])
            ->post(route('locations.switch'), ['location_id' => $south->id, '_token' => $token])
            ->assertRedirect()->assertSessionHas('ext_location_id', $south->id);
    } finally {
        $this->app->instance('env', 'testing');
    }
});

it('describes global all-data authority separately from the creation default', function () {
    $south = contextLocation('Wache Süd');
    $north = contextLocation('Wache Nord');
    contextLocation('Stillgelegt', false);
    $admin = contextMember($south, admin: true);
    $vehicle = Vehicle::factory()->create(['ext_location_id' => $south->id]);

    $this->actingAs($admin)->post(route('locations.switch'), ['location_id' => $north->id])->assertRedirect();
    $this->get('/')->assertOk()
        ->assertSee('Globaler Admin: Zugriff auf alle Standortdaten.')
        ->assertSee('Die Auswahl ist nur die Vorgabe für neue Datensätze.')
        ->assertDontSee('Stillgelegt');
    expect(Vehicle::find($vehicle->id))->not->toBeNull()
        ->and(app(LocationAccess::class)->currentLocationId($admin))->toBe($north->id);
});

it('rejects unknown inactive and foreign switch targets without replacing the selection', function (string $target) {
    $south = contextLocation('Wache Süd');
    $foreign = contextLocation('Wache Nord');
    $inactive = contextLocation('Stillgelegt', false);
    $user = contextMember($south, $inactive);
    $id = match ($target) {
        'unknown' => 999999,
        'inactive' => $inactive->id,
        default => $foreign->id,
    };

    $this->actingAs($user)->withSession(['ext_location_id' => $south->id])
        ->post(route('locations.switch'), ['location_id' => $id])->assertForbidden()
        ->assertSessionHas('ext_location_id', $south->id);
})->with(['unknown', 'inactive', 'foreign']);

it('validates malformed selection values', function () {
    $user = contextMember(contextLocation('Wache Süd'));
    $this->actingAs($user)->withHeader('Referer', 'https://outside.example.com/private')
        ->post(route('locations.switch'), ['location_id' => 'not-an-id'])
        ->assertSessionHasErrors('location_id')->assertRedirect(route('home'));
});

it('rechecks revoked memberships despite a warmed permission memo and stale session', function () {
    $south = contextLocation('Wache Süd');
    $north = contextLocation('Wache Nord');
    $user = contextMember($south, $north);
    expect(app(LocationAccess::class)->roles($user))->toHaveKey($north->id);
    DB::table('x_location_user')->where('user_id', $user->id)->delete();

    $this->actingAs($user)->withSession(['ext_location_id' => $north->id])->get('/')
        ->assertOk()->assertDontSee($north->name)->assertSessionMissing('ext_location_id');
    $this->post(route('locations.switch'), ['location_id' => $north->id])->assertForbidden();
    expect(app(LocationAccess::class)->currentLocationId($user))->toBe($south->id);
});

it('rechecks stale primary membership and global flags directly from the database', function () {
    $south = contextLocation('Wache Süd');
    $north = contextLocation('Wache Nord');
    $stale = contextMember($north, admin: true);
    app(LocationAccess::class)->roles($stale);
    DB::table('users')->where('id', $stale->id)->update(['ext_location_id' => $south->id, 'is_admin' => false]);
    $stale->locations()->sync([$south->id => ['role' => 'member']]);

    $this->actingAs($stale)->withSession(['ext_location_id' => $north->id]);
    $context = app(LocationContext::class)->forUser($stale);
    expect($context['globalAdmin'])->toBeFalse()
        ->and($context['current'])->toBe($south->id)
        ->and($context['locations']->modelKeys())->toBe([$south->id]);
    $this->post(route('locations.switch'), ['location_id' => $north->id])->assertForbidden();
    expect(app(LocationAccess::class)->currentLocationId($stale))->toBe($south->id);
});

it('drops inactive selections and uses an active authorized fallback', function () {
    $south = contextLocation('Wache Süd');
    $north = contextLocation('Wache Nord');
    $user = contextMember($south, $north);
    $north->update(['is_active' => false]);

    $this->actingAs($user)->withSession(['ext_location_id' => $north->id])->get('/')
        ->assertOk()->assertDontSee($north->name)->assertSessionMissing('ext_location_id');
    expect(app(LocationAccess::class)->currentLocationId($user))->toBe($south->id);
});

it('rejects switching by a newly inactive user even when the authenticated model is stale', function () {
    $south = contextLocation('Wache Süd');
    $user = contextMember($south, admin: true);
    DB::table('users')->where('id', $user->id)->update(['is_active' => false]);

    $this->actingAs($user)->withSession(['ext_location_id' => $south->id])
        ->post(route('locations.switch'), ['location_id' => $south->id])->assertForbidden()
        ->assertSessionMissing('ext_location_id');
    expect(app(LocationContext::class)->forUser($user)['locations'])->toHaveCount(0);
});

it('does not create or promote a default Standort just to display missing contexts', function () {
    $south = contextLocation('Wache Süd', false);
    $user = contextMember($south);
    DB::table('users')->where('id', $user->id)->update(['ext_location_id' => null]);
    DB::table('x_locations')->update(['is_default' => false]);
    $before = Location::count();

    $this->actingAs($user)->get('/')->assertOk()->assertSee('Kein aktiver berechtigter Standort')
        ->assertDontSee('id="app-location-select"', false);
    expect(Location::count())->toBe($before)
        ->and(Location::where('is_default', true)->exists())->toBeFalse()
        ->and(fn () => app(LocationAccess::class)->currentLocationId($user))->toThrow(AuthorizationException::class);
    expect(Location::count())->toBe($before);
});

it('renders an empty location installation without creating a default row', function () {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['ext_location_id' => null]);
    if (Schema::hasTable('x_vv_associations')) {
        DB::table('x_vv_associations')->delete();
    }
    DB::table('x_locations')->delete();

    $this->actingAs($user)->get('/')->assertOk()->assertSee('Kein aktiver berechtigter Standort');
    expect(Location::count())->toBe(0);
});

it('uses the same accessible switcher in the existing panel hook', function () {
    $user = contextMember(contextLocation('Wache Süd'), contextLocation('Wache Nord'), admin: true);
    $this->actingAs($user)->get('/admin')->assertOk()
        ->assertSee('id="admin-location-select"', false)
        ->assertSee('for="admin-location-select"', false)
        ->assertSee('Standort übernehmen')->assertDontSee('onchange="this.form.submit()"', false);
});

it('shows no location context to guests and protects the switch route', function () {
    $this->get('/anmelden')->assertOk()->assertDontSee('Standortkontext')->assertDontSee('Standort für neue Datensätze:');
    $this->post(route('locations.switch'), ['location_id' => 1])->assertRedirect();
    expect(app(LocationContext::class)->forUser(null)['locations'])->toHaveCount(0);
});

it('hides context and blocks switching when disabled at runtime', function () {
    $user = contextMember(contextLocation('Wache Süd'), contextLocation('Wache Nord'), admin: true);
    config(['locations.enabled' => false]);

    $this->actingAs($user)->get('/')->assertOk()->assertDontSee('Standortkontext')->assertDontSee('Standort für neue Datensätze:');
    $this->get('/admin')->assertOk()->assertDontSee('id="admin-location-select"', false);
    $this->post(route('locations.switch'), ['location_id' => $user->ext_location_id])->assertNotFound();
});

it('does not register the UI or switch route when disabled before application boot', function () {
    $code = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        echo json_encode([
            'enabled' => config('locations.enabled'),
            'switch' => Illuminate\Support\Facades\Route::has('locations.switch'),
            'view' => view()->exists('locations::partials.app-context'),
        ]);
        PHP;
    $process = new Process([PHP_BINARY, '-r', $code], base_path(), ['LOCATIONS_ENABLED' => 'false']);
    $process->mustRun();

    expect(json_decode($process->getOutput(), true))->toBe(['enabled' => false, 'switch' => false, 'view' => false]);
});

it('never redirects a successful switch to an untrusted referrer', function (string $referer) {
    $user = contextMember(contextLocation('Wache Süd'));
    $this->actingAs($user)->withHeader('Referer', $referer)
        ->post(route('locations.switch'), ['location_id' => $user->ext_location_id])
        ->assertRedirect(route('home'));
})->with(['https://outside.example.com/private', '//outside.example.com', 'http://localhost//outside.example.com', 'http://localhost/\\outside.example.com']);

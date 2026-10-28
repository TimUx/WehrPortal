<?php

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Guardian;
use App\Models\LoginToken;
use App\Models\MailLog;
use App\Models\Module;
use App\Models\User;
use App\Notifications\Concerns\InApp;
use App\Services\Invitation;
use App\Services\MagicLinkService;
use Extensions\Locations\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Kalender\src\Notifications\EventCreatedNotification;
use Modules\Kalender\src\Notifications\GuardianCopyNotification;
use Modules\Kalender\src\Services\EventService;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\Stock;
use Modules\Kleiderkammer\src\Services\IssuingService;
use Modules\Personalverwaltung\src\Services\PersonnelService;
use Modules\Verbandbuch\src\Livewire\EntryForm;

require_once __DIR__.'/../Kalender/Helpers.php';

beforeEach(function () {
    Module::whereIn('key', ['kleiderkammer', 'verbandbuch', 'personalverwaltung'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $this->admin = User::factory()->admin()->create();
});

test('an admin creates a member without e-mail: kept in the list, but no login, no Einladung', function () {
    $this->actingAs($this->admin);
    $locationId = Location::default()->id;
    $memberData = ['locations' => [$locationId], 'ext_location_id' => $locationId];
    Livewire::test(CreateUser::class)->fillForm($memberData + ['name' => 'Paul Pumpe', 'email' => null])->call('create')->assertHasNoFormErrors();
    $kid = User::where('name', 'Paul Pumpe')->sole();
    expect($kid->email)->toBeNull()->and($kid->is_active)->toBeTrue()->and($kid->hasLogin())->toBeFalse();

    // A second one without address is fine (unique ignores empty addresses); an admin needs one
    Livewire::test(CreateUser::class)->fillForm($memberData + ['name' => 'Mia Musik', 'email' => null])->call('create')->assertHasNoFormErrors();
    Livewire::test(CreateUser::class)->fillForm($memberData + ['name' => 'Anna Admin', 'email' => null, 'is_admin' => true])->call('create')->assertHasFormErrors(['email']);

    Livewire::test(ListUsers::class)->assertSee('ohne Login')
        ->assertTableActionHidden('invite', $kid)->assertTableActionHidden('send_login_link', $kid);
    expect(fn () => app(Invitation::class)->create($kid))->toThrow(ValidationException::class);

    // Even a token that somehow exists does not log in
    $raw = Str::random(64);
    LoginToken::create(['user_id' => $kid->id, 'token_hash' => hash('sha256', $raw), 'expires_at' => now()->addHour(), 'created_at' => now()]);
    expect(fn () => app(MagicLinkService::class)->consumeToken($raw, Request::create('/')))->toThrow(ValidationException::class)
        ->and($kid->canAccessPanel(filament()->getPanel('admin')))->toBeFalse();
});

test('a member without e-mail gets Kleidung, appears in the Verbandbuch, and notifications don\'t fail', function () {
    $kid = User::factory()->create(['name' => 'Paul Pumpe', 'email' => null]);

    $type = ItemType::create(['name' => 'JF-T-Shirt']);
    Stock::create(['item_type_id' => $type->id, 'quantity' => 5]);
    $issued = app(IssuingService::class)->issueStock($kid, $type, null, 2);
    expect($issued->user_id)->toBe($kid->id);

    $helper = User::factory()->create();
    $helper->moduleAccess()->create(['module' => 'verbandbuch']);
    Livewire::actingAs($helper)->test(EntryForm::class)->assertSee('Paul Pumpe');

    // Mail is skipped without an address, the entry under the bell stays
    $kid->notify(new class extends BaseNotification
    {
        use InApp;

        public function toMail(object $notifiable): MailMessage
        {
            return (new MailMessage)->subject('Neuer Termin')->line('Jugenddienst am Freitag')->action('Öffnen', url('/'));
        }
    });
    expect($kid->notifications()->count())->toBe(1)->and(MailLog::count())->toBe(0);
});

test('the Erziehungsberechtigten of a child without e-mail still get the Kalender mails', function () {
    activateKalender();
    Notification::fake();
    $kid = kalenderUser(null, ['name' => 'Paul Pumpe', 'email' => null]);
    $mum = Guardian::create(['user_id' => $kid->id, 'name' => 'Petra Pumpe', 'email' => 'petra@example.com']);

    app(EventService::class)->announce(kalenderEvent('2027-06-15 18:00', '20:00', ['title' => 'Jugenddienst', 'rsvp' => true]), kalenderUser('terminverwalter'));

    Notification::assertSentTo($kid, EventCreatedNotification::class);
    Notification::assertSentTo($mum, GuardianCopyNotification::class);
});

test('Personalverwalter create members without e-mail; the import keeps needing one', function () {
    config(['locations.enabled' => false]);
    $pv = User::factory()->create();
    $pv->moduleAccess()->create(['module' => 'personalverwaltung', 'role' => 'personalverwalter']);
    $service = app(PersonnelService::class);

    $kid = $service->createMember($pv, ['name' => 'Mia Musik', 'email' => '']);
    expect($kid->email)->toBeNull()->and($kid->hasLogin())->toBeFalse();
    expect(fn () => $service->createMember($pv, ['name' => 'Kaputt', 'email' => 'keine-adresse']))->toThrow(ValidationException::class);

    $rows = $service->importPreview($pv, "Name;E-Mail\nOhne Adresse;\nBernd Blaulicht;bernd@example.com");
    expect($rows[0]['action'])->toBe('error')->and($rows[1]['action'])->toBe('new');
});

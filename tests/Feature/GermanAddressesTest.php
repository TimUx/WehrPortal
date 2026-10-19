<?php

use App\Filament\Resources\GroupResource;
use App\Filament\Resources\MemberFunctionResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Tables\Enums\RecordActionsPosition;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Kleiderkammer\src\Filament\Resources\EquipmentMessageResource;

test('the app and the admin use German addresses', function () {
    expect(route('profile', absolute: false))->toBe('/personalakte')
        ->and(route('auth.magic-link.form', absolute: false))->toBe('/anmelden')
        ->and(route('fahrzeugbuchung.calendar', absolute: false))->toBe('/fahrzeugbuchung')
        ->and(route('fahrzeugbuchung.my-bookings', absolute: false))->toBe('/fahrzeugbuchung/meine-buchungen')
        ->and(route('filament.admin.resources.kameraden.index', absolute: false))->toBe('/admin/kameraden')
        ->and(route('filament.admin.resources.kameraden.edit', ['record' => 5], absolute: false))->toBe('/admin/kameraden/5/bearbeiten')
        ->and(route('filament.admin.resources.kameraden.create', absolute: false))->toBe('/admin/kameraden/neu');

    // No English path segments left in the app's own routes (packages excluded)
    $english = collect(Route::getRoutes())->map->uri()
        ->reject(fn ($uri) => str_starts_with($uri, 'livewire') || str_starts_with($uri, 'filament/') || str_starts_with($uri, '_') || $uri === 'up')
        ->filter(fn ($uri) => preg_match('#(^|/)(users|groups|functions|bookings|calendar|decisions|profile|avatars|organization|create|edit|login|logout|services|manage-modules|items|members|stocks|devices|defects|equipment|loans|locations|recalls|damages|categories|messages)(/|$)#', $uri))
        // The old addresses only redirect; the signed return link of old mails stays as it was
        ->reject(fn ($uri) => str_ends_with($uri, '{rest?}') || $uri === 'login' || $uri === 'fahrzeugbuchung/bookings/{booking}/return')
        // Filament's own logout endpoint (POST only, never in the address bar)
        ->reject(fn ($uri) => str_ends_with($uri, '/logout') && str_starts_with($uri, 'admin'))
        ->values();

    expect($english->all())->toBe([]);
});

test('old English addresses redirect to the German ones, sub-pages and query included', function () {
    $this->get('/admin/users')->assertRedirect('/admin/kameraden')->assertStatus(301);
    $this->get('/admin/users/5/edit')->assertRedirect('/admin/kameraden/5/bearbeiten');
    $this->get('/admin/users/create')->assertRedirect('/admin/kameraden/neu');
    $this->get('/admin/kleiderkammer/items/create?code=WEHRX')->assertRedirect('/admin/kleiderkammer/einzelteile/neu?code=WEHRX');
    $this->get('/fahrzeugbuchung/calendar')->assertRedirect('/fahrzeugbuchung');
    $this->get('/profile')->assertRedirect('/personalakte');
    $this->get('/auth/login/abc123')->assertRedirect('/anmelden/abc123');
    $this->get('/login')->assertRedirect('/anmelden');
});

test('guests go straight to /anmelden', function () {
    $this->get('/personalakte')->assertRedirect('/anmelden');
});

test('"Als Kamerad ansehen" sits at the start of each row and on "Kamerad bearbeiten"', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->admin()->create());
    $member = User::factory()->create();

    $list = Livewire::test(ListUsers::class);
    expect($list->instance()->getTable()->getRecordActionsPosition())->toBe(RecordActionsPosition::BeforeColumns);
    $list->assertTableActionVisible('impersonate', $member);

    Livewire::test(EditUser::class, ['record' => $member->getKey()])->assertActionVisible('impersonate');
});

test('the return link of a reminder sent before the change still works', function () {
    $booking = Booking::factory()->create();
    $old = URL::signedRoute('fahrzeugbuchung.bookings.return.legacy', $booking);

    expect($old)->toContain('/fahrzeugbuchung/bookings/'.$booking->id.'/return?signature=');
    $this->get($old)->assertOk();
});

test('Abteilungen and Funktionen are a sub-menu of Kameraden; the Kleiderkammer messages are called Mängel & Wäsche', function () {
    expect(GroupResource::getNavigationParentItem())->toBe('Kameraden')
        ->and(MemberFunctionResource::getNavigationParentItem())->toBe('Kameraden')
        ->and(EquipmentMessageResource::getPluralModelLabel())->toBe('Mängel & Wäsche')
        ->and(EquipmentMessageResource::getNavigationLabel())->toBe('Mängel & Wäsche');

    $this->get('/admin/modules')->assertRedirect('/admin/module');
    $this->get('/admin/kleiderkammer/messages')->assertRedirect('/admin/kleiderkammer/maengelmanagement');
    $this->get('/admin/organization-settings-page')->assertRedirect('/admin/einstellungen');
});

<?php

use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use App\Services\Impersonation;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = User::factory()->admin()->create(['name' => 'Anna Admin']);
    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant', 'last_login_at' => null]);
});

test('an admin sees the app as a Kamerad and goes back', function () {
    $this->actingAs($this->admin);

    Livewire::test(ListUsers::class)
        ->assertTableActionVisible('impersonate', $this->hanna)
        ->assertTableActionHidden('impersonate', $this->admin)
        ->callTableAction('impersonate', $this->hanna)
        ->assertRedirect(route('home'));

    expect(auth()->id())->toBe($this->hanna->id)
        ->and(session(Impersonation::SESSION_KEY))->toBe($this->admin->id)
        ->and($this->hanna->refresh()->last_login_at)->toBeNull();   // not a login of hers

    $this->get('/')->assertOk()->assertSee('Du siehst die App als')->assertSee('Hanna Hydrant')->assertSee('Zurück zum Admin');
    $this->get('/admin')->assertForbidden();                         // exactly her rights

    $this->post(route('impersonation.stop'))->assertRedirect('/admin/kameraden');
    expect(auth()->id())->toBe($this->admin->id)->and(session()->has(Impersonation::SESSION_KEY))->toBeFalse();
    $this->get('/')->assertDontSee('Du siehst die App als');
});

test('admins and inactive Kameraden cannot be taken over, and only admins can do it', function () {
    $other = User::factory()->admin()->create();
    $inactive = User::factory()->create(['status' => 'inactive']);
    $impersonation = app(Impersonation::class);

    $this->actingAs($this->admin);
    expect(fn () => $impersonation->start($this->admin, $other))->toThrow(ValidationException::class)
        ->and(fn () => $impersonation->start($this->admin, $inactive))->toThrow(ValidationException::class)
        ->and(fn () => $impersonation->start($this->hanna, User::factory()->create()))->toThrow(ValidationException::class);

    Livewire::test(ListUsers::class)->assertTableActionHidden('impersonate', $inactive)->assertTableActionHidden('impersonate', $other);
});

test('"Zurück zum Admin" without an impersonation just logs out', function () {
    $this->actingAs($this->hanna)->post(route('impersonation.stop'))->assertRedirect('/anmelden');
    $this->assertGuest();
});

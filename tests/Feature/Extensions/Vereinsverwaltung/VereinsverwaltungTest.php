<?php

use App\Models\Module;
use App\Models\User;
use Extensions\Vereinsverwaltung\Livewire\Board;
use Extensions\Vereinsverwaltung\Models\Member;
use Extensions\Vereinsverwaltung\Models\MembershipApplication;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    Module::where('key', 'verein')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    config(['vereinsverwaltung.annual_fee_cents' => 1500]);
});

function membershipApplicationData(array $overrides = []): array
{
    return array_replace([
        'title' => '',
        'first_name' => 'Alex',
        'last_name' => 'Example',
        'birth_date' => '1990-02-03',
        'marital_status' => '',
        'phone' => '',
        'mobile' => '01501234567',
        'email' => 'alex@example.com',
        'street' => 'Musterweg 1',
        'postal_code' => '12345',
        'city' => 'Musterstadt',
        'account_holder' => 'Alex Example',
        'bank_name' => 'Beispielbank',
        'bic' => 'EXAMPLE1',
        'iban' => 'DE89370400440532013000',
        'privacy_consent' => '1',
        'sepa_notice' => '1',
    ], $overrides);
}

it('accepts public applications while encrypting personal and bank data', function () {
    $this->post(route('verein.application.store'), membershipApplicationData())
        ->assertRedirect(route('verein.application'))
        ->assertSessionHas('submitted', true);

    $application = MembershipApplication::firstOrFail();
    $raw = DB::table('x_vv_applications')->where('id', $application->id)->first();

    expect($application->status)->toBe('pending')
        ->and($application->displayName())->toBe('Alex Example')
        ->and($raw->first_name)->not->toContain('Alex')
        ->and($raw->email)->not->toContain('alex@example.com')
        ->and($raw->iban)->not->toContain('DE89370400440532013000')
        ->and($application->consented_at)->not->toBeNull()
        ->and($application->consent_version)->toBe('1');
});

it('does not flash submitted bank details when validation fails', function () {
    $this->post(route('verein.application.store'), membershipApplicationData(['privacy_consent' => null]))
        ->assertRedirect(route('verein.application'))
        ->assertSessionHasErrors('privacy_consent')
        ->assertSessionMissing('_old_input.iban');

    expect(MembershipApplication::count())->toBe(0);
});

it('does not persist honeypot submissions', function () {
    $this->post(route('verein.application.store'), membershipApplicationData(['website' => 'https://spam.example']))
        ->assertRedirect(route('verein.application'))
        ->assertSessionHas('submitted', true);

    expect(MembershipApplication::count())->toBe(0);
});

it('only lets an assigned active board member open the management page', function () {
    $board = User::factory()->create();
    $board->moduleAccess()->create(['module' => 'verein', 'role' => 'vereinsvorstand']);
    $other = User::factory()->create();

    $this->actingAs($board)->get(route('verein.index'))->assertOk();
    $this->actingAs($other)->get(route('verein.index'))->assertForbidden();
    auth()->logout();
    $this->get(route('verein.index'))->assertRedirect(route('login'));
});

it('approves applications as passive members and removes the duplicate application data', function () {
    $board = User::factory()->create();
    $board->moduleAccess()->create(['module' => 'verein', 'role' => 'vereinsvorstand']);
    $application = app(\Extensions\Vereinsverwaltung\Services\MembershipService::class)
        ->submitApplication(collect(membershipApplicationData())->except(['privacy_consent', 'sepa_notice'])->all());

    Livewire::actingAs($board)->test(Board::class)->call('approve', $application->id);

    $member = Member::firstOrFail();
    expect($member->membership_type)->toBe('passive')
        ->and($member->annual_fee_cents)->toBe(1500)
        ->and($member->mandate_status)->toBe('awaiting_signature')
        ->and($member->iban)->toBe('DE89370400440532013000')
        ->and(DB::table('x_vv_members')->where('id', $member->id)->value('iban'))->not->toContain('DE89370400440532013000')
        ->and($application->refresh()->first_name)->toBeNull()
        ->and($application->status)->toBe('approved');
});

it('lets the board document a mandate without creating a payment', function () {
    $board = User::factory()->create();
    $board->moduleAccess()->create(['module' => 'verein', 'role' => 'vereinsvorstand']);
    $member = Member::create([
        'first_name' => 'Alex',
        'last_name' => 'Example',
        'joined_at' => now()->toDateString(),
        'annual_fee_cents' => 1500,
    ]);

    Livewire::actingAs($board)->test(Board::class)
        ->call('edit', $member->id)
        ->set('form.mandate_status', 'documented')
        ->set('form.mandate_signed_at', '2026-10-01')
        ->set('form.iban', 'DE89 3704 0044 0532 0130 00')
        ->call('save')
        ->assertHasNoErrors();

    expect($member->refresh()->mandate_status)->toBe('documented')
        ->and($member->iban)->toBe('DE89370400440532013000')
        ->and(DB::table('x_vv_members')->where('id', $member->id)->value('iban'))->not->toContain('DE89370400440532013000')
        ->and(DB::table('x_vv_members')->count())->toBe(1);
});

it('redacts rejected application data and permits active and passive board membership separately', function () {
    $board = User::factory()->create();
    $board->moduleAccess()->create(['module' => 'verein', 'role' => 'vereinsvorstand']);
    $application = app(\Extensions\Vereinsverwaltung\Services\MembershipService::class)
        ->submitApplication(collect(membershipApplicationData())->except(['privacy_consent', 'sepa_notice'])->all());

    Livewire::actingAs($board)->test(Board::class)->call('reject', $application->id);

    expect($application->refresh()->status)->toBe('rejected')
        ->and($application->first_name)->toBeNull()
        ->and($application->iban)->toBeNull();

    $member = Member::create([
        'first_name' => 'Sam',
        'last_name' => 'Example',
        'membership_type' => 'active',
        'joined_at' => now()->toDateString(),
        'annual_fee_cents' => 1500,
        'mandate_status' => 'awaiting_signature',
    ]);
    expect($member->membership_type)->toBe('active');
});

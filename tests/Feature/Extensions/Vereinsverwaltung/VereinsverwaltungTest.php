<?php

use App\Models\Module;
use App\Models\User;
use Extensions\Locations\Models\Location;
use Extensions\Vereinsverwaltung\Livewire\Board;
use Extensions\Vereinsverwaltung\Models\Association;
use Extensions\Vereinsverwaltung\Models\Member;
use Extensions\Vereinsverwaltung\Models\MembershipApplication;
use Extensions\Vereinsverwaltung\Services\MembershipService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    Module::where('key', 'verein')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    config(['vereinsverwaltung.annual_fee_cents' => 1500]);
    testAssociation();
});

function testAssociation(?Location $location = null, string $name = 'Feuerwehrverein'): Association
{
    $location ??= Location::default();

    return Association::query()->withoutGlobalScopes()->firstOrCreate(
        ['location_id' => $location->id],
        ['name' => $name],
    );
}

function assignAssociationBoard(User $user, Association $association): void
{
    $association->boardMembers()->syncWithoutDetaching([$user->id]);
    $user->moduleAccess()->updateOrCreate(['module' => 'verein'], ['role' => 'vereinsvorstand']);
}

function membershipApplicationData(array $overrides = []): array
{
    return array_replace([
        'association_id' => testAssociation()->id,
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
        'youth_member' => '0',
        'photo_consent' => '0',
        'youth_photo_consent' => '0',
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
        ->and($application->consent_version)->toBe('1')
        ->and($application->photo_consent)->toBeFalse();
});

it('lets applicants select a configured Verein and keeps the association through approval', function () {
    $board = User::factory()->create();
    $association = testAssociation();
    assignAssociationBoard($board, $association);
    $service = app(MembershipService::class);

    $this->get(route('verein.application'))
        ->assertOk()
        ->assertSee($association->name);

    $this->post(route('verein.application.store'), membershipApplicationData(['association_id' => $association->id]))
        ->assertRedirect(route('verein.application'))
        ->assertSessionHas('submitted', true);

    $application = MembershipApplication::firstOrFail();
    expect($application->association_id)->toBe($association->id);

    $member = $service->approveApplication($board, $association->id, $application->id);
    expect($member->association_id)->toBe($association->id);
});

it('rejects a public application for an unconfigured association', function () {
    $this->from(route('verein.application'))
        ->post(route('verein.application.store'), membershipApplicationData(['association_id' => 999999]))
        ->assertRedirect(route('verein.application'))
        ->assertSessionHasErrors('association_id');

    expect(MembershipApplication::count())->toBe(0);
});

it('accepts youth applications without SEPA details and records free membership', function () {
    $association = testAssociation();
    $data = membershipApplicationData([
        'birth_date' => now()->subYears(12)->toDateString(),
        'youth_member' => '1',
        'account_holder' => '',
        'bank_name' => '',
        'bic' => '',
        'iban' => '',
    ]);
    unset($data['sepa_notice']);

    $this->post(route('verein.application.store'), $data)
        ->assertRedirect(route('verein.application'))
        ->assertSessionHas('submitted', true);

    $application = MembershipApplication::firstOrFail();
    expect($application->youth_member)->toBeTrue()
        ->and($application->iban)->toBeNull()
        ->and($application->account_holder)->toBeNull();

    $board = User::factory()->create();
    assignAssociationBoard($board, $association);
    app(MembershipService::class)->approveApplication($board, $association->id, $application->id);
    $member = Member::firstOrFail();
    expect($member->youth_member)->toBeTrue()
        ->and($member->annual_fee_cents)->toBe(0)
        ->and($member->mandate_status)->toBe('not_required')
        ->and($member->iban)->toBeNull();
});

it('requires a separate youth photo consent from applicants aged fourteen or older', function () {
    $data = membershipApplicationData([
        'birth_date' => now()->subYears(14)->toDateString(),
        'youth_member' => '1',
        'photo_consent' => '1',
        'youth_photo_consent' => '0',
    ]);
    unset($data['sepa_notice']);

    $this->from(route('verein.application'))->post(route('verein.application.store'), $data)
        ->assertRedirect(route('verein.application'))
        ->assertSessionHasErrors('youth_photo_consent');
    expect(MembershipApplication::count())->toBe(0);

    $this->post(route('verein.application.store'), [...$data, 'youth_photo_consent' => '1'])
        ->assertRedirect(route('verein.application'))
        ->assertSessionHas('submitted', true);
    $application = MembershipApplication::firstOrFail();
    expect($application->photo_consent)->toBeTrue()
        ->and($application->youth_photo_consent)->toBeTrue()
        ->and($application->photo_consent_recorded_at)->not->toBeNull();
});

it('allows photo consent for children under fourteen with the guardian consent alone', function () {
    $data = membershipApplicationData([
        'birth_date' => now()->subYears(10)->toDateString(),
        'youth_member' => '1',
        'photo_consent' => '1',
        'youth_photo_consent' => '0',
    ]);
    unset($data['sepa_notice']);

    $this->post(route('verein.application.store'), $data)
        ->assertRedirect(route('verein.application'))
        ->assertSessionHas('submitted', true);

    $application = MembershipApplication::firstOrFail();
    expect($application->photo_consent)->toBeTrue()
        ->and($application->youth_photo_consent)->toBeFalse();
});

it('allows a youth application when the guardian declines photo consent', function () {
    $data = membershipApplicationData([
        'birth_date' => now()->subYears(15)->toDateString(),
        'youth_member' => '1',
        'photo_consent' => '0',
        'youth_photo_consent' => '0',
    ]);
    unset($data['sepa_notice']);

    $this->post(route('verein.application.store'), $data)
        ->assertRedirect(route('verein.application'))
        ->assertSessionHas('submitted', true);

    expect(MembershipApplication::firstOrFail()->photo_consent)->toBeFalse();
});

it('requires the SEPA acknowledgement for non-youth applicants', function () {
    $data = membershipApplicationData();
    unset($data['sepa_notice']);

    $this->from(route('verein.application'))->post(route('verein.application.store'), $data)
        ->assertRedirect(route('verein.application'))
        ->assertSessionHasErrors('sepa_notice');

    expect(MembershipApplication::count())->toBe(0);
});

it('does not flash submitted bank details when validation fails', function () {
    $this->from(route('verein.application'))->post(route('verein.application.store'), membershipApplicationData(['privacy_consent' => null]))
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
    $association = testAssociation();
    $board = User::factory()->create();
    assignAssociationBoard($board, $association);
    $other = User::factory()->create();
    $other->moduleAccess()->create(['module' => 'verein', 'role' => 'vereinsvorstand']);

    $this->actingAs($board)->get(route('verein.index'))->assertOk();
    $this->actingAs($other)->get(route('verein.index'))->assertForbidden();
    auth()->logout();
    $this->get(route('verein.index'))->assertRedirect(route('auth.magic-link.form'));
});

it('limits board applications, members and linked personnel to its assigned Verein and Standort', function () {
    $association = testAssociation();
    $otherLocation = Location::create([
        'name' => 'Musterstadt-Süd',
        'slug' => 'musterstadt-sued',
        'is_active' => true,
    ]);
    $otherAssociation = testAssociation($otherLocation, 'Förderverein Süd');
    $board = User::factory()->create();
    assignAssociationBoard($board, $association);

    $firstApplication = app(MembershipService::class)
        ->submitApplication(collect(membershipApplicationData(['first_name' => 'Anna']))->except(['privacy_consent', 'sepa_notice'])->all());
    $otherApplication = app(MembershipService::class)
        ->submitApplication(collect(membershipApplicationData(['association_id' => $otherAssociation->id, 'first_name' => 'Bernd']))->except(['privacy_consent', 'sepa_notice'])->all());

    $sameLocationPersonnel = User::factory()->create([
        'name' => 'Klara Standort Nord',
        'ext_location_id' => $association->location_id,
    ]);
    $otherLocationPersonnel = User::factory()->create([
        'name' => 'Klara Standort Süd',
        'ext_location_id' => $otherAssociation->location_id,
    ]);

    $component = Livewire::actingAs($board)->test(Board::class)
        ->assertSee('Anna Example')
        ->assertDontSee('Bernd Example');

    $visiblePersonnelIds = app(MembershipService::class)
        ->personnelForAssociation($association)->modelKeys();
    expect($visiblePersonnelIds)->toContain($sameLocationPersonnel->id)
        ->and($visiblePersonnelIds)->not->toContain($otherLocationPersonnel->id);

    expect(fn () => app(MembershipService::class)
        ->rejectApplication($board, $association->id, $otherApplication->id))
        ->toThrow(ModelNotFoundException::class);

    expect($firstApplication->association_id)->toBe($association->id)
        ->and($otherApplication->association_id)->toBe($otherAssociation->id)
        ->and($component->get('associationId'))->toBe($association->id);
});

it('approves applications as passive members and removes the duplicate application data', function () {
    $association = testAssociation();
    $board = User::factory()->create();
    assignAssociationBoard($board, $association);
    $application = app(MembershipService::class)
        ->submitApplication(collect(membershipApplicationData())->except(['privacy_consent', 'sepa_notice'])->all());

    Livewire::actingAs($board)->test(Board::class)->call('approve', $application->id);

    $member = Member::firstOrFail();
    expect($member->membership_type)->toBe('passive')
        ->and($member->association_id)->toBe($association->id)
        ->and($member->annual_fee_cents)->toBe(1500)
        ->and($member->mandate_status)->toBe('awaiting_signature')
        ->and($member->iban)->toBe('DE89370400440532013000')
        ->and(DB::table('x_vv_members')->where('id', $member->id)->value('iban'))->not->toContain('DE89370400440532013000')
        ->and($application->refresh()->first_name)->toBeNull()
        ->and($application->status)->toBe('approved');
});

it('lets the board document a mandate without creating a payment', function () {
    $association = testAssociation();
    $board = User::factory()->create();
    assignAssociationBoard($board, $association);
    $member = Member::create([
        'association_id' => $association->id,
        'ext_location_id' => $association->location_id,
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
        ->and($member->photo_consent_recorded_at)->toBeNull()
        ->and(DB::table('x_vv_members')->count())->toBe(1);
});

it('removes mandate data and fees when the board marks a member as youth', function () {
    $association = testAssociation();
    $board = User::factory()->create();
    assignAssociationBoard($board, $association);
    $member = Member::create([
        'association_id' => $association->id,
        'ext_location_id' => $association->location_id,
        'first_name' => 'Alex',
        'last_name' => 'Example',
        'joined_at' => now()->toDateString(),
        'annual_fee_cents' => 1500,
        'mandate_status' => 'documented',
        'account_holder' => 'Alex Example',
        'iban' => 'DE89370400440532013000',
        'mandate_reference' => 'VV-001',
        'mandate_signed_at' => '2026-10-01',
    ]);

    Livewire::actingAs($board)->test(Board::class)
        ->call('edit', $member->id)
        ->set('form.youth_member', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($member->refresh()->youth_member)->toBeTrue()
        ->and($member->annual_fee_cents)->toBe(0)
        ->and($member->mandate_status)->toBe('not_required')
        ->and($member->iban)->toBeNull()
        ->and($member->mandate_reference)->toBeNull();
});

it('links personnel records to the Verein without copying contact data', function () {
    $person = User::factory()->create(['phone' => '0123456789', 'email' => 'kamerad@example.com']);
    $locationId = $person->getAttribute('ext_location_id');

    $service = app(MembershipService::class);
    $service->setPersonnelMembership($person, true);

    $member = Member::query()->where('user_id', $person->id)->firstOrFail();
    $stored = DB::table('x_vv_members')->where('id', $member->id)->first();
    expect($member->displayName())->toBe($person->name)
        ->and($member->membership_type)->toBe('active')
        ->and($member->status)->toBe('member')
        ->and($member->ext_location_id)->toBe($locationId)
        ->and($member->first_name)->toBeNull()
        ->and($member->phone)->toBeNull()
        ->and($member->email)->toBeNull()
        ->and($stored->first_name)->toBeNull()
        ->and($stored->phone)->toBeNull()
        ->and($stored->email)->toBeNull();

    $service->setPersonnelMembership($person, false);
    expect($member->refresh()->status)->toBe('left')
        ->and($member->left_at)->not->toBeNull();
});

it('redacts rejected application data and permits active and passive board membership separately', function () {
    $association = testAssociation();
    $board = User::factory()->create();
    assignAssociationBoard($board, $association);
    $application = app(MembershipService::class)
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

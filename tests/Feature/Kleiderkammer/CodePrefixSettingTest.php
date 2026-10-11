<?php

use App\Filament\Pages\OrganizationSettingsPage;
use App\Models\Setting;
use App\Models\User;
use App\Services\OrganizationSettings;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

test('code prefix defaults to WEHR when no setting is stored', function () {
    expect(app(OrganizationSettings::class)->codePrefix())->toBe('WEHR');
});

test('admin can save a new code prefix', function () {
    Livewire::actingAs($this->admin)
        ->test(OrganizationSettingsPage::class)
        ->set('org_code_prefix', 'FF99')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(OrganizationSettings::class)->codePrefix())->toBe('FF99');
});

test('clearing the code prefix falls back to WEHR', function () {
    Setting::set('org_code_prefix', 'FF99');

    Livewire::actingAs($this->admin)
        ->test(OrganizationSettingsPage::class)
        ->set('org_code_prefix', '')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(OrganizationSettings::class)->codePrefix())->toBe('WEHR');
});

test('code prefix must be uppercase alphanumeric', function () {
    Livewire::actingAs($this->admin)
        ->test(OrganizationSettingsPage::class)
        ->set('org_code_prefix', 'ff99')
        ->call('save')
        ->assertHasErrors(['org_code_prefix']);
});

test('code prefix must be at least 2 characters', function () {
    Livewire::actingAs($this->admin)
        ->test(OrganizationSettingsPage::class)
        ->set('org_code_prefix', 'A')
        ->call('save')
        ->assertHasErrors(['org_code_prefix']);
});

test('code prefix is capped at 6 characters', function () {
    Livewire::actingAs($this->admin)
        ->test(OrganizationSettingsPage::class)
        ->set('org_code_prefix', 'ABCDEFG')
        ->call('save')
        ->assertHasErrors(['org_code_prefix']);
});

test('page loads the stored prefix into the form', function () {
    Setting::set('org_code_prefix', 'KFW1');

    Livewire::actingAs($this->admin)
        ->test(OrganizationSettingsPage::class)
        ->assertSet('org_code_prefix', 'KFW1');
});

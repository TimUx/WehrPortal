<?php

use App\Models\Contact;
use App\Models\Group;
use App\Models\User;

test('the contacts page can search all public contact fields', function () {
    $group = Group::create(['name' => 'Jugendfeuerwehr']);
    Contact::create([
        'role' => 'Jugendwart',
        'name' => 'Hanna Hydrant',
        'phone' => '+49 (123) 456-789',
        'email' => 'hanna@example.com',
        'note' => 'Erreichbar abends',
        'group_id' => $group->id,
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('contacts'))
        ->assertOk()
        ->assertSee('Ansprechpartner suchen')
        ->assertSee('jugendwart hanna hydrant', false)
        ->assertSee('tel:+49123456789');
});

test('tel links allow only a normalized phone number', function () {
    expect((new Contact(['phone' => ' +49 (123) 456-789 ']))->telUrl())->toBe('tel:+49123456789')
        ->and((new Contact(['phone' => '04101 / 12345']))->telUrl())->toBe('tel:0410112345')
        ->and((new Contact(['phone' => '+49 123 ext. 4']))->telUrl())->toBeNull()
        ->and((new Contact(['phone' => '++49 123']))->telUrl())->toBeNull()
        ->and((new Contact(['phone' => '12']))->telUrl())->toBeNull();
});

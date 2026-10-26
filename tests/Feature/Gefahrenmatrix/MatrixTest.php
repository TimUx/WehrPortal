<?php

use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Modules\Gefahrenmatrix\src\Livewire\Matrix;

beforeEach(function () {
    Module::where('key', 'gefahrenmatrix')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');

    $this->member = User::factory()->create();
    $this->member->moduleAccess()->create(['module' => 'gefahrenmatrix']);
});

test('members can select and clear hazards without persisting a matrix', function () {
    Livewire::actingAs($this->member)
        ->test(Matrix::class)
        ->assertSee('aria-label="Gefahrenmatrix-Tabelle"', false)
        ->call('toggle', 'atemgift')
        ->assertSet('selected', ['atemgift'])
        ->assertSee('Atemgift')
        ->assertSee('Menschen')
        ->assertSee('Mannschaft')
        ->call('toggle', 'atemgift')
        ->assertSet('selected', [])
        ->call('toggle', 'explosion')
        ->call('clear')
        ->assertSet('selected', []);

    expect(Schema::hasTable('gm_matrices'))->toBeFalse();
});

test('unknown hazards cannot be selected', function () {
    Livewire::actingAs($this->member)
        ->test(Matrix::class)
        ->call('toggle', 'unbekannt')
        ->assertNotFound();
});

test('only active module users can open the matrix', function () {
    $this->actingAs($this->member)->get(route('gefahrenmatrix.index'))->assertOk()->assertSee('Merkstütze');

    $other = User::factory()->create();
    $this->actingAs($other)->get(route('gefahrenmatrix.index'))->assertForbidden();

    Module::where('key', 'gefahrenmatrix')->update(['is_active' => false]);
    Cache::forget('modules_active_keys');
    $this->actingAs($this->member)->get(route('gefahrenmatrix.index'))->assertNotFound();
});

<?php

use App\Models\User;
use Livewire\Livewire;
use Modules\Wasserkarte\src\Livewire\SourceForm;

test('offline worker has a root scope and only caches the offline fallback and static assets', function () {
    $this->get('/sw.js')
        ->assertOk()
        ->assertHeader('Service-Worker-Allowed', '/')
        ->assertHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
        ->assertSee('request.mode === \'navigate\'', false)
        ->assertSee("'/anmelden'", false)
        ->assertSee("'/livewire/'", false)
        ->assertSee("'/build/assets/'", false);
});

test('offline draft script uses IndexedDB without replaying submissions', function () {
    $this->get('/offline/offline.js')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
        ->assertSee('indexedDB.open', false)
        ->assertSee('Entwurf wiederherstellen', false)
        ->assertSee('data-discard', false)
        ->assertSee('store.put', false)
        ->assertSee('store.get', false)
        ->assertSee('store.delete', false)
        ->assertDontSee('pending-forms', false)
        ->assertDontSee('fetch(', false);
});

test('offline draft support is disabled by configuration without disabling the worker', function () {
    config(['offline.enabled' => false]);

    $this->get('/offline/offline.js')->assertNotFound();
    $this->get('/sw.js')->assertOk();
    $this->actingAs(User::factory()->create())->get(route('profile'))->assertDontSee('data-offline-pwa', false);
});

test('the water-source form opts in only its explicitly marked fields', function () {
    Livewire::actingAs(User::factory()->admin()->create())
        ->test(SourceForm::class)
        ->assertSee('data-offline-draft="wasserkarte-source"', false)
        ->assertSee('data-offline-draft-field', false)
        ->assertDontSee('wire:model="form.notes" data-offline-draft-field', false)
        ->assertSee('wire:submit="save"', false);
});

test('the authenticated app exposes the German offline status and user-scoped draft controls', function () {
    $member = User::factory()->create();

    $this->actingAs($member)->get(route('profile'))
        ->assertOk()
        ->assertSee('data-offline-pwa', false)
        ->assertSee('data-user-id="'.$member->id.'"', false)
        ->assertSee('data-offline-storage-error', false)
        ->assertSee('Offline-Modus', false)
        ->assertSee('Jetzt synchronisieren', false);
});

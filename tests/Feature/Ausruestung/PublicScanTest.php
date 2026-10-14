<?php

use Filament\Facades\Filament;
use Livewire\Livewire;
use Modules\Ausruestung\src\Filament\Pages\PublicScanPage;
use Modules\Ausruestung\src\Support\PublicScan;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    activateAusruestung();
    $this->helmet = equipment(['code' => 'AR-0042', 'serial_number' => 'SN-GEHEIM-77']);
});

test('without login the scan leads to the login – until a Gerätewart switches the Geräteinfo on', function () {
    $this->get('/i/AR-0042')->assertRedirect(route('ausruestung.show', $this->helmet));
    $this->get(route('ausruestung.public', 'AR-0042'))->assertRedirect(route('ausruestung.show', $this->helmet));

    $wart = ausruestungMember('geraetewart');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::actingAs($wart)->test(PublicScanPage::class)->assertSee('ausgeschaltet')->callAction('toggle');
    auth()->logout();
    expect(PublicScan::enabled())->toBeTrue();

    $this->get('/i/AR-0042')->assertRedirect(route('ausruestung.public', 'AR-0042'));
    $this->get(route('ausruestung.public', 'AR-0042'))->assertOk()
        ->assertSee('Feuerwehrhelm HPS 7000')->assertSee('Einsatzbereit')->assertSee('Nächste Prüfung')
        ->assertDontSee('SN-GEHEIM-77')->assertHeader('X-Robots-Tag');

    // Logged in, the scan goes on to the device page as before
    $this->actingAs(ausruestungMember())->get('/i/AR-0042')->assertRedirect(route('ausruestung.show', $this->helmet));
    $this->get('/geraeteinfo/GIBTSNICHT')->assertNotFound();
});

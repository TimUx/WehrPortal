<?php

use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Modules\Fahrzeugbuchung\src\Filament\Pages\FahrzeugbuchungDashboard;
use Modules\Fahrzeugbuchung\src\Filament\Widgets\MonitorLinkWidget;
use Modules\Fahrzeugbuchung\src\Filament\Widgets\TopBookersWidget;
use Modules\Fahrzeugbuchung\src\Filament\Widgets\UpcomingBookingsWidget;
use Modules\Fahrzeugbuchung\src\Filament\Widgets\VehicleBookingsWidget;
use Modules\Kleiderkammer\src\Filament\Pages\ScanPage;

beforeEach(function () {
    Module::whereIn('key', ['kleiderkammer', 'fahrzeugbuchung'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $this->member = User::factory()->create(['name' => 'Hanna Hydrant']);
    $this->member->moduleAccess()->create(['module' => 'kleiderkammer']);
    $this->warden = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $this->warden->moduleAccess()->create(['module' => 'kleiderkammer', 'role' => 'kleiderwart']);
});

test('Kleiderwarte get the red Scan button on Kleiderkammer pages, members do not', function () {
    $this->actingAs($this->member)->get(route('kleiderkammer.index'))->assertOk()
        ->assertDontSee('data-kk-scan-button', false);

    $this->actingAs($this->warden)->get(route('kleiderkammer.index'))->assertOk()
        ->assertSee('data-kk-scan-button', false)->assertSee('href="'.ScanPage::getUrl().'"', false);
    // Not outside the Kleiderkammer, and not on the Scan-Seite itself
    $this->actingAs($this->warden)->get(route('home'))->assertOk()->assertDontSee('data-kk-scan-button', false);
    $this->actingAs($this->warden)->get(ScanPage::getUrl())->assertOk()->assertDontSee('data-kk-scan-button', false);
});

test('the admin user menu shows the e-mail and links to the Personalakte; the Fahrzeugbuchung overview puts the Monitor last', function () {
    $admin = User::factory()->admin()->create(['name' => 'Anna Admin', 'email' => 'anna.admin@example.com']);
    $html = $this->actingAs($admin)->get(FahrzeugbuchungDashboard::getUrl())->assertOk()->getContent();

    expect($html)->toContain('anna.admin@example.com')->toContain('Meine Personalakte')->toContain('href="'.route('profile').'"');
    expect((new FahrzeugbuchungDashboard)->getWidgets())->toBe([
        TopBookersWidget::class,
        UpcomingBookingsWidget::class,
        VehicleBookingsWidget::class,
        MonitorLinkWidget::class,
    ]);
});

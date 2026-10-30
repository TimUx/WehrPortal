<?php

namespace Modules\Atemschutz\src\Filament\Pages;

use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Atemschutz\src\Services\MonitorService;

/**
 * /admin/atemschutz/berechnung – cylinder, consumption and reserve for the expected Einsatzzeit (Atemschutzwart, admin)
 *
 * @property Schema $form
 */
class SettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'atemschutz/berechnung';

    protected static ?string $title = 'Berechnungsgrundlagen';

    protected static ?string $navigationLabel = 'Berechnungsgrundlagen';

    protected static string|\UnitEnum|null $navigationGroup = 'Atemschutz';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calculator';

    protected static ?int $navigationSort = 20;

    protected string $view = 'atemschutz::filament.settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->is_active && MonitorService::canManage($user);
    }

    public function mount(): void
    {
        $settings = MonitorService::settings();
        $this->form->fill([
            'liters' => $settings['cylinder_liters'], 'consumption' => $settings['consumption_lpm'], 'reserve' => $settings['reserve_bar'],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Forms\Components\TextInput::make('liters')->label('Flasche (Liter)')->numeric()->required()->minValue(1)->maxValue(15),
            Forms\Components\TextInput::make('consumption')->label('Verbrauch (l/min)')->integer()->required()->minValue(10)->maxValue(150),
            Forms\Components\TextInput::make('reserve')->label('Reserve (bar)')->integer()->required()->minValue(20)->maxValue(150),
        ])->columns(3);
    }

    public function save(MonitorService $monitor): void
    {
        $state = $this->form->getState();
        try {
            $monitor->saveSettings(auth()->user(), (float) $state['liters'], (int) $state['consumption'], (int) $state['reserve']);
        } catch (ValidationException $e) {
            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

            return;
        }
        Notification::make()->title('Gespeichert')->body('Die Werte gelten für neu registrierte Trupps.')->success()->send();
    }
}

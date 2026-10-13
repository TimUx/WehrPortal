<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who may drive which vehicle. Modules that know about it register here (Führerscheine: classes, who may
 * drive; Unterweisungen: Einweisung on the vehicle); the Fahrtenbuch (warning when a trip starts) and the
 * Fahrzeuge (who may drive it) ask – without knowing those modules.
 */
class DriverRegistry
{
    /** @var array<string, array{module: string, warning: callable, drivers: ?callable}> */
    private array $providers = [];

    /**
     * @param  callable(User $driver, int $vehicleId): ?string  $warning  a hint when something is missing, null when fine or unknown
     * @param  ?callable(int $vehicleId): ?array{required: string, users: Collection}  $drivers  who may drive it, null when the vehicle has no requirement
     */
    public function register(string $module, callable $warning, ?callable $drivers = null): void
    {
        $this->providers[$module] = compact('module', 'warning', 'drivers');
    }

    /** All hints of the switched-on modules, joined */
    public function warning(User $driver, int $vehicleId): ?string
    {
        $hints = collect($this->active())->map(fn ($p) => ($p['warning'])($driver, $vehicleId))->filter()->values();

        return $hints->isEmpty() ? null : $hints->implode(' ');
    }

    /** @return array{required: string, users: Collection}|null */
    public function drivers(int $vehicleId): ?array
    {
        foreach ($this->active() as $p) {
            if ($p['drivers'] && ($result = ($p['drivers'])($vehicleId))) {
                return $result;
            }
        }

        return null;
    }

    private function active(): array
    {
        $registry = app(ModuleRegistry::class);

        return array_filter($this->providers, fn ($p) => $registry->isActive($p['module']));
    }
}

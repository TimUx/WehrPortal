<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who may drive which vehicle. The module that knows the Führerscheine registers here; the Fahrtenbuch
 * (warning when a trip starts) and the Fahrzeuge (who may drive it) ask – without knowing that module.
 */
class DriverRegistry
{
    /** @var array{module: string, warning: callable, drivers: callable}|null */
    private ?array $provider = null;

    /**
     * @param  callable(User $driver, int $vehicleId): ?string  $warning  a hint when the driver lacks a valid Fahrerlaubnis, null when fine or unknown
     * @param  callable(int $vehicleId): ?array{required: string, users: Collection}  $drivers  who may drive it, null when the vehicle has no requirement
     */
    public function register(string $module, callable $warning, callable $drivers): void
    {
        $this->provider = compact('module', 'warning', 'drivers');
    }

    public function warning(User $driver, int $vehicleId): ?string
    {
        return $this->active() ? ($this->provider['warning'])($driver, $vehicleId) : null;
    }

    /** @return array{required: string, users: Collection}|null */
    public function drivers(int $vehicleId): ?array
    {
        return $this->active() ? ($this->provider['drivers'])($vehicleId) : null;
    }

    private function active(): bool
    {
        return $this->provider !== null && app(ModuleRegistry::class)->isActive($this->provider['module']);
    }
}

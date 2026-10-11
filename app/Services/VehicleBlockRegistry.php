<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Times when a vehicle can't be booked (e.g. Werkstatt), reported by modules such as the
 * Fahrzeugverwaltung and respected by the Fahrzeugbuchung, so neither depends on the other.
 * A provider gets the period and optionally vehicle ids and returns the blocks overlapping it.
 */
class VehicleBlockRegistry
{
    /** @var array<string, callable(CarbonInterface, CarbonInterface, ?array): iterable<array{vehicle_id: int, starts_at: CarbonInterface, ends_at: ?CarbonInterface, reason: string}>> */
    private array $providers = [];

    public function register(string $module, callable $provider): void
    {
        $this->providers[$module] = $provider;
    }

    /**
     * Blocks overlapping [start, end) of active modules; ends_at null means "until further notice".
     *
     * @param  ?list<int>  $vehicleIds
     * @return Collection<int, array{vehicle_id: int, starts_at: CarbonInterface, ends_at: ?CarbonInterface, reason: string}>
     */
    public function between(CarbonInterface $start, CarbonInterface $end, ?array $vehicleIds = null): Collection
    {
        $registry = app(ModuleRegistry::class);

        return collect($this->providers)
            ->filter(fn ($provider, $module) => $registry->isActive($module))
            ->flatMap(fn ($provider) => collect($provider($start, $end, $vehicleIds)))
            ->values();
    }

    /** Why the vehicle can't be booked in this period, or null when it can */
    public function reason(int $vehicleId, CarbonInterface $start, CarbonInterface $end): ?string
    {
        return $this->between($start, $end, [$vehicleId])->first()['reason'] ?? null;
    }
}

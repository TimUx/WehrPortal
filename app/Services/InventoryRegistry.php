<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The Bestand across modules: vehicles, Geräte, Kleidung, Schlüssel … Each module registers
 * a search over its objects and, optionally, its planned Ersatzbeschaffungen. Both closures get
 * the user and return null or an empty collection for what that user may not see; inactive
 * modules are skipped.
 *
 * Search rows: {title, code, place, state, tone (green|amber|red|gray|blue), url}
 * Replacement rows: {year, title, detail, cost_cents, url}
 */
class InventoryRegistry
{
    /** @var array<string, array{label: string, search: callable, replacements: ?callable, order: int}> */
    private array $sources = [];

    /**
     * @param  callable(string $term, User $user): ?iterable  $search
     * @param  null|callable(User $user, int $fromYear, int $toYear): ?iterable  $replacements
     */
    public function register(string $module, string $label, callable $search, ?callable $replacements = null, int $order = 50): void
    {
        $this->sources[$module] = ['label' => $label, 'search' => $search, 'replacements' => $replacements, 'order' => $order];
    }

    /** Whether the Bestand has something for this user: an active source module they can use */
    public function availableFor(User $user): bool
    {
        return $this->active()->keys()->contains(fn ($module) => $user->canUseModule($module));
    }

    /** @return array<string, string> module => label of the active sources */
    public function labels(): array
    {
        return $this->active()->map(fn ($s) => $s['label'])->all();
    }

    /** @return Collection<string, array{label: string, rows: Collection}> by module, only those with hits */
    public function search(string $term, User $user): Collection
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return collect();
        }

        return $this->active()->map(fn ($s, $module) => ['label' => $s['label'], 'rows' => $this->call($s['search'], $term, $user)->take(50)])
            ->filter(fn ($s) => $s['rows']->isNotEmpty());
    }

    /** @return Collection<int, array{year: int, title: string, detail: ?string, cost_cents: ?int, url: ?string, source: string}> */
    public function replacements(User $user, int $fromYear, int $toYear): Collection
    {
        return $this->active()->filter(fn ($s) => $s['replacements'] !== null)
            ->flatMap(fn ($s) => $this->call($s['replacements'], $user, $fromYear, $toYear)->map(fn ($row) => [...$row, 'source' => $s['label']]))
            ->sortBy([['year', 'asc'], ['title', 'asc']])->values();
    }

    /** Whether the user can plan Ersatzbeschaffungen in at least one module (a closure returns null when not) */
    public function canPlan(User $user): bool
    {
        return $this->active()->contains(function ($s) use ($user) {
            try {
                return $s['replacements'] !== null && ($s['replacements'])($user, 0, 0) !== null;
            } catch (Throwable $e) {
                report($e);

                return false;
            }
        });
    }

    private function active(): Collection
    {
        $modules = app(ModuleRegistry::class);

        return collect($this->sources)->filter(fn ($s, $module) => $modules->isActive($module))->sortBy('order');
    }

    private function call(callable $fn, mixed ...$args): Collection
    {
        try {
            return collect($fn(...$args) ?? []);
        } catch (Throwable $e) {
            report($e);

            return collect();
        }
    }
}

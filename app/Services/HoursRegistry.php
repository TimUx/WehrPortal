<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The Stundennachweis: modules tell how many minutes a Kamerad spent where (attended Dienste and Termine,
 * Einsätze, Lehrgangstage, Schichten). Nothing is entered twice – the hours come from what is there anyway.
 */
class HoursRegistry
{
    /** @var array<string, array{module: string, source: string, rows: callable}> */
    private array $sources = [];

    /**
     * @param  callable(list<int> $userIds, Carbon $from, Carbon $to): iterable<array{user_id: int, date: Carbon, title: string, minutes: int}>  $rows
     */
    public function register(string $module, string $source, callable $rows): void
    {
        $this->sources[$module.'.'.$source] = compact('module', 'source', 'rows');
    }

    /** @return list<string> the sources of the switched-on modules */
    public function sources(): array
    {
        return array_values(collect($this->active())->pluck('source')->unique()->all());
    }

    /**
     * Every row of the Kameraden in the period, newest first.
     *
     * @param  list<int>  $userIds
     * @return Collection<int, array{user_id: int, date: Carbon, title: string, minutes: int, source: string}>
     */
    public function rows(array $userIds, Carbon $from, Carbon $to): Collection
    {
        if ($userIds === []) {
            return collect();
        }

        return collect($this->active())->flatMap(fn ($s) => collect(($s['rows'])($userIds, $from, $to))
            ->map(fn ($row) => [...$row, 'minutes' => max(0, (int) $row['minutes']), 'source' => $s['source']]))
            ->filter(fn ($row) => $row['minutes'] > 0)
            ->sortByDesc(fn ($row) => $row['date']->timestamp)->values();
    }

    /** @return Collection<int, array{user_id: int, date: Carbon, title: string, minutes: int, source: string}> */
    public function forUser(User $user, Carbon $from, Carbon $to): Collection
    {
        return $this->rows([$user->id], $from, $to);
    }

    /** "12,5 h" */
    public static function format(int $minutes): string
    {
        return str_replace('.', ',', (string) round($minutes / 60, 1)).' h';
    }

    private function active(): array
    {
        $registry = app(ModuleRegistry::class);

        return array_filter($this->sources, fn ($s) => $registry->isActive($s['module']));
    }
}

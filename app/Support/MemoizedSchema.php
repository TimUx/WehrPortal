<?php

namespace App\Support;

use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What the Schema facade talks to: the connection's schema builder, with the answers to hasTable()
 * and hasColumn() remembered for the rest of the request. Optional parts of the app ask "does this
 * table exist?" in many places, often once per Kamerad in a list, and each question was a catalog
 * query. Only "yes" is remembered (a table that exists doesn't vanish during a request, one that is
 * missing may be created by a migration), and every other call – create, drop, table … – forgets
 * all of it, so migrations and tests always see the real state.
 *
 * @mixin Builder
 */
class MemoizedSchema
{
    /** @var array<string, true> */
    private array $known = [];

    public function hasTable(string $table): bool
    {
        return $this->remember('t:'.$table, fn () => $this->builder()->hasTable($table));
    }

    public function hasColumn(string $table, string $column): bool
    {
        return $this->remember('c:'.$table.'.'.strtolower($column), fn () => $this->builder()->hasColumn($table, $column));
    }

    public function forget(): void
    {
        $this->known = [];
    }

    /** @param  array<int, mixed>  $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        $this->forget();

        return $this->builder()->{$method}(...$arguments);
    }

    private function remember(string $key, callable $ask): bool
    {
        $key = DB::getDefaultConnection().'|'.$key;
        if (isset($this->known[$key])) {
            return true;
        }
        if ($ask()) {
            $this->known[$key] = true;

            return true;
        }

        return false;
    }

    private function builder(): Builder
    {
        return DB::connection()->getSchemaBuilder();
    }
}

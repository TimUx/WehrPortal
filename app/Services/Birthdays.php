<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** The next birthdays of active Kameraden; 29 February is celebrated on 28 February in other years */
class Birthdays
{
    /**
     * @param  bool  $onlyShown  only Kameraden who agreed to appear on the start page
     * @return Collection<int, array{user: User, date: Carbon, age: int, days: int}> sorted by date
     */
    public function upcoming(int $limit = 5, bool $onlyShown = false, ?Carbon $today = null): Collection
    {
        $today = ($today ?? now('Europe/Berlin'))->copy()->startOfDay();

        return User::active()->whereNotNull('birth_date')
            ->when($onlyShown, fn ($q) => $q->where('show_birthday', true))
            ->get()
            ->map(function (User $user) use ($today) {
                $next = $this->inYear($user->birth_date, $today->year);
                if ($next->lt($today)) {
                    $next = $this->inYear($user->birth_date, $today->year + 1);
                }

                return ['user' => $user, 'date' => $next, 'age' => $next->year - $user->birth_date->year, 'days' => (int) $today->diffInDays($next)];
            })
            ->sortBy([['days', 'asc'], [fn ($a, $b) => strcmp($a['user']->name, $b['user']->name)]])
            ->take($limit)
            ->values();
    }

    /** Everyone whose birthday is on the first upcoming birthday date (there can be several) */
    public function next(bool $onlyShown = true, ?Carbon $today = null): Collection
    {
        $all = $this->upcoming(PHP_INT_MAX, $onlyShown, $today);

        return $all->isEmpty() ? $all : $all->where('days', $all->first()['days'])->values();
    }

    private function inYear(Carbon $birth, int $year): Carbon
    {
        $day = $birth->month === 2 && $birth->day === 29 && ! Carbon::create($year)->isLeapYear() ? 28 : $birth->day;

        return Carbon::create($year, $birth->month, $day, 0, 0, 0, 'Europe/Berlin');
    }
}

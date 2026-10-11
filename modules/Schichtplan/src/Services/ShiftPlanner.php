<?php

namespace Modules\Schichtplan\src\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Schichtplan\src\Models\Assignment;
use Modules\Schichtplan\src\Models\Participant;
use Modules\Schichtplan\src\Models\Plan;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Fills a plan automatically. One attempt goes block by block:
 *
 * 1. Who works: as many people as there are free places, chosen by fairness – those with the fewest
 *    shifts so far first, people who would exceed max_consecutive blocks in a row only if nobody else
 *    is left, people with a new station among the free places before others, people who just worked
 *    after those who rested, chance for ties.
 * 2. Who goes where: a maximum bipartite matching between these people and the free places, with an
 *    edge wherever the station is new for the person (rotation: everyone through every station before
 *    repeats); the rest go where they have been least often.
 * 3. A repair pass swaps stations of two people within a block whenever that lowers the repeats.
 *
 * Several attempts with different chance are computed in memory and the best one is saved: fewest
 * avoidable repeats of a station, then the most even number of shifts.
 * Hand-set (locked) assignments are kept; places nobody can take stay empty and show up as gaps.
 */
class ShiftPlanner
{
    public const ATTEMPTS = 40;

    public function fill(Plan $plan, ?int $seed = null): void
    {
        $random = new Randomizer($seed === null ? null : new Mt19937($seed));

        DB::transaction(function () use ($plan, $random) {
            $plan->assignments()->where('locked', false)->delete();

            $slots = $plan->slots()->get()->values();
            $stations = $plan->stations()->get();
            $participants = $plan->participants()->get();

            if ($stations->isEmpty() || $participants->isEmpty()) {
                return;
            }

            $locked = $plan->assignments()->get()->map(fn (Assignment $a) => [$a->slot_id, $a->station_id, $a->user_id])->all();

            $best = null;
            $bestScore = null;

            for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
                $result = $this->attempt($plan, $slots, $stations, $participants, $locked, $random);
                $score = $this->score($result, $locked, $participants, $stations->count());

                if ($bestScore === null || $score < $bestScore) {
                    [$best, $bestScore] = [$result, $score];
                }

                if ($score[0] === 0 && $score[1] <= 1) {
                    break; // cannot get better
                }
            }

            $now = now();
            Assignment::insert(array_map(fn ($a) => [
                'plan_id' => $plan->id, 'slot_id' => $a[0], 'station_id' => $a[1], 'user_id' => $a[2],
                'locked' => false, 'created_at' => $now, 'updated_at' => $now,
            ], $best));
        });
    }

    /** @return array<int, array{0: int, 1: int, 2: int}> new [slot, station, user] triples */
    private function attempt(Plan $plan, Collection $slots, Collection $stations, Collection $participants, array $locked, Randomizer $random): array
    {
        $placed = [];
        $total = [];
        $atStation = [];
        foreach ($locked as [$slotId, $stationId, $userId]) {
            $placed[$slotId][] = $userId;
            $total[$userId] = ($total[$userId] ?? 0) + 1;
            $atStation[$userId][$stationId] = ($atStation[$userId][$stationId] ?? 0) + 1;
        }

        // A random order per attempt, so ties do not always favour the same people
        $shuffle = array_flip($random->shuffleArray($participants->pluck('user_id')->all()));
        $new = [];

        foreach ($slots as $index => $slot) {
            $lockedHere = array_count_values(array_map(fn ($a) => $a[1], array_filter($locked, fn ($a) => $a[0] === $slot->id)));

            $seats = [];
            foreach ($random->shuffleArray($stations->all()) as $station) {
                for ($i = $station->capacity - ($lockedHere[$station->id] ?? 0); $i > 0; $i--) {
                    $seats[] = $station->id;
                }
            }

            if ($seats === []) {
                continue;
            }

            $streak = fn (int $id) => $this->streak($id, $index, $slots, $placed);

            $workers = $participants
                ->filter(fn (Participant $p) => $p->isAvailableFor($slot) && ! in_array($p->user_id, $placed[$slot->id] ?? [], true))
                ->map(fn (Participant $p) => $p->user_id)
                ->sortBy([
                    fn ($a, $b) => (int) ($streak($a) >= $plan->max_consecutive) <=> (int) ($streak($b) >= $plan->max_consecutive),
                    fn ($a, $b) => ($total[$a] ?? 0) <=> ($total[$b] ?? 0),
                    fn ($a, $b) => (int) ! $this->hasNewSeat($a, $seats, $atStation) <=> (int) ! $this->hasNewSeat($b, $seats, $atStation),
                    fn ($a, $b) => (int) ($streak($a) > 0) <=> (int) ($streak($b) > 0),
                    fn ($a, $b) => $shuffle[$a] <=> $shuffle[$b],
                ])
                ->take(count($seats))
                ->values()
                ->all();

            foreach ($this->seat($workers, $seats, $atStation) as $userId => $stationId) {
                $new[] = [$slot->id, $stationId, $userId];
                $placed[$slot->id][] = $userId;
                $total[$userId] = ($total[$userId] ?? 0) + 1;
                $atStation[$userId][$stationId] = ($atStation[$userId][$stationId] ?? 0) + 1;
            }
        }

        return $this->reduceRepeats($new, $atStation);
    }

    /**
     * Puts the workers on the seats: first a maximum matching on "station is new for the person",
     * then everyone left where they have been least often.
     *
     * @param  int[]  $workers  user ids
     * @param  int[]  $seats  station id per free place
     * @return array<int, int> user id => station id
     */
    private function seat(array $workers, array $seats, array $atStation): array
    {
        $seatOf = []; // seat index => worker index

        foreach (array_keys($workers) as $w) {
            $visited = [];
            $this->augment($w, $workers, $seats, $atStation, $seatOf, $visited);
        }

        $result = [];
        foreach ($seatOf as $s => $w) {
            $result[$workers[$w]] = $seats[$s];
        }

        $freeSeats = array_values(array_diff(array_keys($seats), array_keys($seatOf)));
        foreach ($workers as $userId) {
            if (isset($result[$userId]) || $freeSeats === []) {
                continue;
            }

            usort($freeSeats, fn ($a, $b) => ($atStation[$userId][$seats[$a]] ?? 0) <=> ($atStation[$userId][$seats[$b]] ?? 0));
            $result[$userId] = $seats[array_shift($freeSeats)];
        }

        return $result;
    }

    /** Kuhn's augmenting path step for worker $w */
    private function augment(int $w, array $workers, array $seats, array $atStation, array &$seatOf, array &$visited): bool
    {
        foreach ($seats as $s => $stationId) {
            if (isset($visited[$s]) || isset($atStation[$workers[$w]][$stationId])) {
                continue;
            }

            $visited[$s] = true;

            if (! isset($seatOf[$s]) || $this->augment($seatOf[$s], $workers, $seats, $atStation, $seatOf, $visited)) {
                $seatOf[$s] = $w;

                return true;
            }
        }

        return false;
    }

    /** Within a block, two people swap stations whenever that lowers the number of repeats */
    private function reduceRepeats(array $new, array $count): array
    {
        $cost = fn (int $n) => max(0, $n - 1);
        $bySlot = [];
        foreach ($new as $i => $a) {
            $bySlot[$a[0]][] = $i;
        }

        for ($round = 0; $round < 10; $round++) {
            $improved = false;

            foreach ($bySlot as $indexes) {
                foreach ($indexes as $x => $i) {
                    foreach (array_slice($indexes, $x + 1) as $j) {
                        [, $sa, $ua] = $new[$i];
                        [, $sb, $ub] = $new[$j];

                        if ($sa === $sb) {
                            continue;
                        }

                        $before = $cost($count[$ua][$sa]) + $cost($count[$ua][$sb] ?? 0) + $cost($count[$ub][$sb]) + $cost($count[$ub][$sa] ?? 0);
                        $after = $cost($count[$ua][$sa] - 1) + $cost(($count[$ua][$sb] ?? 0) + 1) + $cost($count[$ub][$sb] - 1) + $cost(($count[$ub][$sa] ?? 0) + 1);

                        if ($after < $before) {
                            $count[$ua][$sa]--;
                            $count[$ub][$sb]--;
                            $count[$ua][$sb] = ($count[$ua][$sb] ?? 0) + 1;
                            $count[$ub][$sa] = ($count[$ub][$sa] ?? 0) + 1;
                            $new[$i][1] = $sb;
                            $new[$j][1] = $sa;
                            $improved = true;
                        }
                    }
                }
            }

            if (! $improved) {
                break;
            }
        }

        return $new;
    }

    /**
     * [avoidable repeats, spread of shifts] – lower is better. A repeat is avoidable as long as the
     * person has not had every station; with more shifts than stations some repeats are unavoidable.
     * The number of filled places is the same in every attempt, so it needs no comparison.
     */
    private function score(array $new, array $locked, Collection $participants, int $stationCount): array
    {
        $total = array_fill_keys($participants->pluck('user_id')->all(), 0);
        $count = [];
        foreach (array_merge($locked, $new) as [, $stationId, $userId]) {
            $total[$userId] = ($total[$userId] ?? 0) + 1;
            $count[$userId][$stationId] = ($count[$userId][$stationId] ?? 0) + 1;
        }

        $avoidable = 0;
        foreach ($count as $userId => $perStation) {
            $repeats = $total[$userId] - count($perStation);
            $avoidable += max(0, $repeats - max(0, $total[$userId] - $stationCount));
        }

        return [$avoidable, $total === [] ? 0 : max($total) - min($total)];
    }

    private function hasNewSeat(int $userId, array $seats, array $atStation): bool
    {
        foreach ($seats as $stationId) {
            if (! isset($atStation[$userId][$stationId])) {
                return true;
            }
        }

        return false;
    }

    /** How many blocks directly before this one the person worked without a break */
    private function streak(int $userId, int $index, Collection $slots, array $placed): int
    {
        $count = 0;

        for ($i = $index - 1; $i >= 0; $i--) {
            $previous = $slots[$i];

            // A gap in time between blocks counts as a break
            if (! $previous->ends_at->equalTo($slots[$i + 1]->starts_at) || ! in_array($userId, $placed[$previous->id] ?? [], true)) {
                break;
            }

            $count++;
        }

        return $count;
    }
}
